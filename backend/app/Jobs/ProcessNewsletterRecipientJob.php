<?php

namespace App\Jobs;

use App\Models\MigrationItem;
use App\Models\MigrationRun;
use App\Services\Migration\MigrationRunReportWriter;
use App\Services\Migration\NewsletterRecipientFingerprint;
use App\Services\Migration\NewsletterRecipientPayloadMapper;
use App\Services\Migration\ShopifyNewsletterSyncService;
use App\Services\Migration\ShopifyTranslationSyncService;
use App\Services\Magento\MagentoClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessNewsletterRecipientJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1200;

    public int $tries = 50;

    private int $runId;

    /** @var array<string, mixed> */
    private array $recipient;

    /**
     * @param array<string, mixed> $recipient
     */
    public function __construct(int $runId, array $recipient)
    {
        $this->runId = $runId;
        $this->recipient = $recipient;
    }

    public function handle(): void
    {
        $run = MigrationRun::query()->with('shop.magentoConnection')->find($this->runId);
        if (!$run) {
            return;
        }

        if (in_array($run->status, ['cancelled', 'finished', 'failed'], true)) {
            return;
        }

        $shop = $run->shop;
        if (!$shop) {
            return;
        }

        $sourceId = trim((string) ($this->recipient['id'] ?? ''));
        if ($sourceId === '') {
            // Fallback to stable source ID based on email.
            $emailFallback = trim((string) ($this->recipient['email'] ?? ''));
            if ($emailFallback === '') {
                return;
            }
            $sourceId = 'email:' . hash('sha256', strtolower($emailFallback));
        }

        $item = MigrationItem::query()->firstOrCreate([
            'migration_run_id' => $run->id,
            'entity_type' => 'newsletter',
            'source_id' => $sourceId,
        ], [
            'status' => 'queued',
        ]);

        if (in_array($item->status, ['skipped', 'succeeded'], true)) {
            return;
        }

        $item->status = 'running';
        $item->started_at = now();
        $item->error_message = null;
        $item->save();

        $mapper = app(NewsletterRecipientPayloadMapper::class);
        $fingerprints = app(NewsletterRecipientFingerprint::class);
        $sync = app(ShopifyNewsletterSyncService::class);

        try {
            $email = $mapper->email($this->recipient);
            if ($email === '') {
                $this->markFailed($run, $item, 'Missing email', ['recipient' => $this->recipient]);
                return;
            }

            $isActive = $mapper->isActiveRecipient($this->recipient);
            $payload = $mapper->mapToShopifyCustomerPayload($this->recipient, $isActive, $shop);

            $recipientLocale = '';
            if ($shop->magentoConnection) {
                $magento = app(MagentoClient::class);
                $storeViews = $magento->getStoreViews($shop->magentoConnection);
                $storeId = trim((string) ($this->recipient['salesChannelId'] ?? ''));
                foreach ($storeViews as $sv) {
                    if ($sv['id'] === $storeId) {
                        $recipientLocale = $sv['locale'] ?? '';
                        break;
                    }
                }
            }
            if ($recipientLocale !== '') {
                $payload['locale'] = $recipientLocale;
            }

            $fp = $fingerprints->make($payload);

            // Skip if unchanged compared to current item (within same run)
            if ($item->fingerprint && hash_equals((string) $item->fingerprint, $fp)) {
                $item->status = 'skipped';
                $item->finished_at = now();
                $item->save();

                $run->refresh();
                $run->processed++;
                $run->save();
                $this->appendReportRow($run, $item, 'skipped');
                return;
            }

            // Skip if already successfully migrated in a previous run (fingerprint unchanged)
            $previousFp = $this->latestSucceededFingerprint($shop->id, $sourceId);
            if (is_string($previousFp) && $previousFp !== '' && is_string($fp) && $fp !== '' && hash_equals($previousFp, $fp)) {
                $item->status = 'skipped';
                $item->fingerprint = $fp;
                $item->finished_at = now();
                $item->save();

                $run->refresh();
                $run->processed++;
                $run->save();
                $this->appendReportRow($run, $item, 'skipped');
                return;
            }

            $res = $sync->upsertRecipient($shop, $sourceId, $email, $payload);

            if (!empty($res['errors']) || !empty($res['userErrors'])) {
                $this->markFailed($run, $item, 'Shopify newsletter upsert failed', $res);
                return;
            }

            $gid = (string) ($res['customerGid'] ?? '');

            $item->status     = 'succeeded';
            $item->fingerprint = $fp;
            $item->shopify_gid = $gid !== '' ? $gid : null;
            $item->finished_at = now();
            $item->save();

            // --- Non-blocking: store newsletter recipient language preference ---
            if ($gid !== '') {
                try {
                    $conn = $shop->magentoConnection;
                    if ($conn) {
                        $magento = app(MagentoClient::class);
                        $storeViews = $magento->getStoreViews($conn);
                        $storeId = trim((string) ($this->recipient['salesChannelId'] ?? ''));
                        foreach ($storeViews as $sv) {
                            if ($sv['id'] === $storeId) {
                                $translationSync = app(ShopifyTranslationSyncService::class);
                                $translationSync->storeLanguagePreferenceMetafield(
                                    $shop, $gid,
                                    $sv['locale'],
                                    $sv['name']
                                );
                                break;
                            }
                        }
                    }
                } catch (\Throwable) {
                    // Non-fatal — newsletter migration already succeeded
                }
            }

            $run->refresh();
            $run->processed++;
            $run->succeeded++;
            $run->save();
            $this->appendReportRow($run, $item, 'succeeded', $email);
        } catch (\Throwable $e) {
            Log::error('Newsletter migration item failed', [
                'run_id' => $run->id,
                'shop' => $shop->shop_domain,
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
            ]);

            $this->markFailed($run, $item, $e->getMessage(), [
                'exception' => get_class($e),
            ]);
        }
    }

    private function markFailed(MigrationRun $run, MigrationItem $item, string $message, array $context): void
    {
        $item->status = 'failed';
        $item->error_message = $message;
        $item->error_context = $context;
        $item->finished_at = now();
        $item->save();

        $run->refresh();
        if ($run->status !== 'cancelled') {
            $run->processed++;
            $run->failed++;
            $run->save();
        }

        $this->appendReportRow($run, $item, 'failed');
    }

    private function latestSucceededFingerprint(int $shopId, string $sourceId): ?string
    {
        $item = MigrationItem::query()
            ->whereHas('run', function ($q) use ($shopId) {
                $q->where('shop_id', $shopId)
                  ->where('type', 'newsletter');
            })
            ->where('entity_type', 'newsletter')
            ->where('source_id', $sourceId)
            ->where('status', 'succeeded')
            ->whereNotNull('fingerprint')
            ->orderByDesc('id')
            ->first();

        return $item ? (string) $item->fingerprint : null;
    }

    private function appendReportRow(MigrationRun $run, MigrationItem $item, string $status, ?string $email = null): void
    {
        try {
            $writer = app(MigrationRunReportWriter::class);
            $resolvedEmail = $email;
            if (! is_string($resolvedEmail) || $resolvedEmail === '') {
                $resolvedEmail = trim((string) ($this->recipient['email'] ?? ''));
            }

            $writer->appendRow($run, [
                'email' => $resolvedEmail,
                'status' => $status,
                'reason' => $status === 'failed'
                    ? $writer->humanizeFailureReason($item)
                    : ($status === 'skipped' ? 'No changes detected (fingerprint matched)' : ''),
                'migrated_at_utc' => $item->finished_at ? $item->finished_at->toDateTimeString() : '',
            ]);
        } catch (\Throwable) {
            // ignore
        }
    }
}

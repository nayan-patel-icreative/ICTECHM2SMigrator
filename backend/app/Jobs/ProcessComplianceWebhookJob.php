<?php

namespace App\Jobs;

use App\Models\Shop;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessComplianceWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private array $event;

    /**
     * Create a new job instance.
     */
    public function __construct(array $event)
    {
        $this->event = $event;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $topic = (string) ($this->event['topic'] ?? '');
        $shopDomain = (string) ($this->event['shop_domain'] ?? '');
        $payload = $this->event['payload'] ?? [];

        if ($shopDomain === '') {
            Log::warning('Compliance webhook missing shop domain', ['topic' => $topic]);
            return;
        }

        $shop = Shop::query()->where('shop_domain', $shopDomain)->first();
        if (!$shop) {
            Log::info('Compliance webhook shop not found; ignoring', ['shop' => $shopDomain, 'topic' => $topic]);
            return;
        }

        if (in_array($topic, ['customers/redact', 'customers/data_request'], true)) {
            Log::info('Processed customer compliance webhook (no stored customer data yet)', [
                'shop' => $shopDomain,
                'topic' => $topic,
                'payload' => $payload,
            ]);
            return;
        }

        if ($topic === 'shop/redact') {
            $shop->delete();
            Log::info('Deleted shop data due to shop/redact', ['shop' => $shopDomain]);
            return;
        }

        Log::warning('Unhandled compliance webhook topic', ['shop' => $shopDomain, 'topic' => $topic]);
    }
}

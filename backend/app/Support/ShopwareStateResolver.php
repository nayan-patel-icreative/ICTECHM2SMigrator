<?php

namespace App\Support;

class ShopwareStateResolver
{
    /**
     * Extract Shopware state machine technical name from an order, transaction, or delivery entity.
     */
    public static function technicalName(mixed $entity): string
    {
        if (!is_array($entity)) {
            return '';
        }

        $candidates = [
            data_get($entity, 'stateMachineState.technicalName'),
            data_get($entity, 'state.technicalName'),
            data_get($entity, 'attributes.stateMachineState.technicalName'),
            data_get($entity, 'attributes.state.technicalName'),
        ];

        foreach ($candidates as $candidate) {
            $name = strtolower(trim((string) $candidate));
            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }

    public static function stateId(mixed $entity): string
    {
        if (!is_array($entity)) {
            return '';
        }

        return trim((string) (
            data_get($entity, 'stateId')
            ?: data_get($entity, 'state_id')
            ?: data_get($entity, 'attributes.stateId')
            ?: ''
        ));
    }
}

<?php

namespace App\Models\Concerns;

/** Stamps the node a row was created on (spec §3.2). Rows arriving by sync keep their original node. */
trait HasOriginNode
{
    public static function bootHasOriginNode(): void
    {
        static::creating(function ($model) {
            if (! $model->getAttribute('origin_node')) {
                $model->setAttribute('origin_node', (string) config('erp.node.id'));
            }
        });
    }
}

<?php

namespace Tests\Concerns;

use App\Models\Table;

trait InteractsWithPublicVisits
{
    /** Existing HTTP scenarios enter their fixture visit explicitly. Tests
     * for missing/stale credentials use postJson directly instead. */
    protected function postPublicJson($uri, array $data = [], array $headers = [], $options = 0)
    {
        if (preg_match('~^/api/v1/public/tables/([^/]+)/(orders|requests/)~', $uri, $matches)) {
            $table = Table::query()->where('public_token', $matches[1])->first();
            $token = $table?->sessions()->latest('id')->value('feedback_token');
            if ($token && ! array_key_exists('X-Table-Visit', $headers)) {
                $headers['X-Table-Visit'] = $token;
            }
        }

        return $this->postJson($uri, $data, $headers, $options);
    }
}

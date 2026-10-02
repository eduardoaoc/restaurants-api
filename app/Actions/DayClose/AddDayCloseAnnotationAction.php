<?php

namespace App\Actions\DayClose;

use App\Models\AuditLog;
use App\Models\RestaurantDayClose;
use App\Models\RestaurantDayCloseAnnotation;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\DayClose\DayCloseFormat;
use Illuminate\Support\Facades\DB;

/**
 * Adds a post-close note to a Cierre Diario (CARTA 9.1A) — the only way to
 * correct or complete a close after the fact. Never touches the close's
 * report, hash, totals or has_incidents.
 */
class AddDayCloseAnnotationAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(RestaurantDayClose $dayClose, User $actor, string $body): RestaurantDayCloseAnnotation
    {
        return DB::transaction(function () use ($dayClose, $actor, $body) {
            $annotation = RestaurantDayCloseAnnotation::query()->create([
                'restaurant_day_close_id' => $dayClose->id,
                'restaurant_id' => $dayClose->restaurant_id,
                'body' => DayCloseFormat::plainText($body),
                'created_by_user_id' => $actor->id,
                'created_by_name_snapshot' => $actor->name,
                'created_at' => now(),
            ]);

            $this->auditLogger->log(
                organizationId: $dayClose->organization_id,
                restaurantId: $dayClose->restaurant_id,
                actorType: AuditLog::ACTOR_USER,
                actor: $actor,
                event: AuditLog::EVENT_DAY_CLOSE_ANNOTATION_ADDED,
                resourceType: AuditLog::RESOURCE_DAY_CLOSE,
                resourceId: $dayClose->id,
                metadata: ['annotation_id' => $annotation->id, 'business_date' => $dayClose->business_date->format('Y-m-d')],
            );

            return $annotation;
        });
    }
}

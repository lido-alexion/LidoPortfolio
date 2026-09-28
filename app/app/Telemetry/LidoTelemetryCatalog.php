<?php

namespace App\Telemetry;

/**
 * Central StoX telemetry names (FEAT-052 §8).
 */
final class LidoTelemetryCatalog
{
    public const HTTP_SERVER = 'stox.http.server';

    public const BUSINESS_AUTH_LOGIN_SUCCEEDED = 'stox.auth.login_succeeded';

    public const BUSINESS_AUTH_LOGIN_FAILED = 'stox.auth.login_failed';

    public const BUSINESS_ROUTE_VIEW = 'stox.ui.route_view';

    public const BUSINESS_SCREENER_RUN = 'stox.screener.run';

    public const BUSINESS_FUNDAMENTALS_VIEW = 'stox.fundamentals.view';

    public const BUSINESS_FUNDAMENTALS_AI_INSIGHTS = 'stox.fundamentals.ai_insights';

    public const BUSINESS_ML_INSIGHTS_VIEW = 'stox.ml.insights_view';

    public const BUSINESS_RECOMMENDATION_VIEW = 'stox.recommendation.viewed';

    public const BUSINESS_RECOMMENDATION_ACCEPTED = 'stox.recommendation.accepted';

    public const BUSINESS_ORDER_PLACEMENT_REQUESTED = 'stox.order.placement_requested';

    public const BUSINESS_ORDER_PLACED = 'stox.order.placed';

    public const BUSINESS_ORDER_CANCEL_REQUESTED = 'stox.order.cancel_requested';

    public const BUSINESS_QUEUE_JOB = 'stox.queue.job';

    public const BUSINESS_SCHEDULER_TASK = 'stox.scheduler.task';

    public const BUSINESS_TELEMETRY_COLLECTOR_PROBE = 'stox.telemetry.collector_probe';
}

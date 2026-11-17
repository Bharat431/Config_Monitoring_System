<?php

namespace Config;

use App\Domain\Diff\DiffEngine;
use App\Domain\Diff\Flattener;
use App\Domain\Parsing\ParserRegistry;
use App\Infrastructure\Http\CurlHttpClient;
use App\Infrastructure\Http\HttpClientInterface;
use App\Infrastructure\Http\UrlGuard;
use App\Infrastructure\Logging\JsonLogger;
use App\Infrastructure\Metrics\Metrics;
use App\Infrastructure\Notifications\NotificationService;
use App\Infrastructure\Notifications\PayloadFactory;
use App\Infrastructure\Notifications\WebhookSigner;
use App\Infrastructure\Queue\EventBus;
use App\Infrastructure\Reliability\CircuitBreaker;
use App\Infrastructure\Tracing\Tracer;
use App\Models\AuditLogModel;
use App\Models\ChangeEventModel;
use App\Models\CircuitBreakerModel;
use App\Models\DeadLetterModel;
use App\Models\DeliveryAttemptModel;
use App\Models\EventQueueModel;
use App\Models\FileSnapshotModel;
use App\Models\MetricCounterModel;
use App\Models\MonitoredFileModel;
use App\Models\NotificationTargetModel;
use App\Models\SystemStateModel;
use App\Services\DemoService;
use App\Services\PathGuard;
use App\Services\QueueWorkerService;
use App\Services\StatsService;
use App\Services\WatcherService;
use CodeIgniter\Config\BaseService;

class Services extends BaseService
{
    public static function monitorConfig(bool $getShared = true): Monitor
    {
        if ($getShared) {
            return static::getSharedInstance('monitorConfig');
        }

        return config('Monitor');
    }

    public static function jsonLogger(bool $getShared = true): JsonLogger
    {
        if ($getShared) {
            return static::getSharedInstance('jsonLogger');
        }

        return new JsonLogger(static::monitorConfig());
    }

    public static function metrics(bool $getShared = true): Metrics
    {
        if ($getShared) {
            return static::getSharedInstance('metrics');
        }

        return new Metrics(static::monitorConfig(), model(MetricCounterModel::class));
    }

    public static function tracer(bool $getShared = true): Tracer
    {
        if ($getShared) {
            return static::getSharedInstance('tracer');
        }

        return new Tracer(static::monitorConfig());
    }

    public static function parserRegistry(bool $getShared = true): ParserRegistry
    {
        if ($getShared) {
            return static::getSharedInstance('parserRegistry');
        }

        return new ParserRegistry();
    }

    public static function flattener(bool $getShared = true): Flattener
    {
        if ($getShared) {
            return static::getSharedInstance('flattener');
        }

        return new Flattener();
    }

    public static function diffEngine(bool $getShared = true): DiffEngine
    {
        if ($getShared) {
            return static::getSharedInstance('diffEngine');
        }

        return new DiffEngine(static::flattener(false));
    }

    public static function pathGuard(bool $getShared = true): PathGuard
    {
        if ($getShared) {
            return static::getSharedInstance('pathGuard');
        }

        return new PathGuard(static::monitorConfig());
    }

    public static function urlGuard(bool $getShared = true): UrlGuard
    {
        if ($getShared) {
            return static::getSharedInstance('urlGuard');
        }

        return new UrlGuard(static::monitorConfig());
    }

    public static function httpClient(bool $getShared = true): HttpClientInterface
    {
        if ($getShared) {
            return static::getSharedInstance('httpClient');
        }

        return new CurlHttpClient(static::monitorConfig(), static::urlGuard(), static::jsonLogger());
    }

    public static function payloadFactory(bool $getShared = true): PayloadFactory
    {
        if ($getShared) {
            return static::getSharedInstance('payloadFactory');
        }

        return new PayloadFactory();
    }

    public static function webhookSigner(bool $getShared = true): WebhookSigner
    {
        if ($getShared) {
            return static::getSharedInstance('webhookSigner');
        }

        return new WebhookSigner();
    }

    public static function circuitBreaker(bool $getShared = true): CircuitBreaker
    {
        if ($getShared) {
            return static::getSharedInstance('circuitBreaker');
        }

        return new CircuitBreaker(
            static::monitorConfig(),
            model(CircuitBreakerModel::class),
            static::jsonLogger(),
        );
    }

    public static function notificationService(bool $getShared = true): NotificationService
    {
        if ($getShared) {
            return static::getSharedInstance('notificationService');
        }

        return new NotificationService(
            static::monitorConfig(),
            static::httpClient(),
            static::payloadFactory(),
            static::webhookSigner(),
            static::jsonLogger(),
            static::metrics(),
        );
    }

    public static function eventBus(bool $getShared = true): EventBus
    {
        if ($getShared) {
            return static::getSharedInstance('eventBus');
        }

        return new EventBus(
            model(ChangeEventModel::class),
            model(NotificationTargetModel::class),
            model(EventQueueModel::class),
            static::metrics(),
            static::jsonLogger(),
        );
    }

    public static function watcherService(bool $getShared = true): WatcherService
    {
        if ($getShared) {
            return static::getSharedInstance('watcherService');
        }

        return new WatcherService(
            static::monitorConfig(),
            model(MonitoredFileModel::class),
            model(FileSnapshotModel::class),
            static::parserRegistry(),
            static::diffEngine(),
            static::flattener(),
            static::eventBus(),
            static::metrics(),
            static::jsonLogger(),
            static::tracer(),
            static::pathGuard(),
            model(SystemStateModel::class),
        );
    }

    public static function queueWorker(bool $getShared = true): QueueWorkerService
    {
        if ($getShared) {
            return static::getSharedInstance('queueWorker');
        }

        return new QueueWorkerService(
            static::monitorConfig(),
            model(EventQueueModel::class),
            model(ChangeEventModel::class),
            model(NotificationTargetModel::class),
            model(DeadLetterModel::class),
            model(DeliveryAttemptModel::class),
            static::circuitBreaker(),
            static::notificationService(),
            static::eventBus(),
            static::metrics(),
            static::jsonLogger(),
            static::tracer(),
            model(SystemStateModel::class),
        );
    }

    public static function stats(bool $getShared = true): StatsService
    {
        if ($getShared) {
            return static::getSharedInstance('stats');
        }

        return new StatsService(
            static::monitorConfig(),
            model(MonitoredFileModel::class),
            model(ChangeEventModel::class),
            model(EventQueueModel::class),
            model(DeliveryAttemptModel::class),
            model(DeadLetterModel::class),
            model(CircuitBreakerModel::class),
            model(NotificationTargetModel::class),
            model(SystemStateModel::class),
        );
    }

    public static function audit(bool $getShared = true): AuditLogModel
    {
        if ($getShared) {
            return static::getSharedInstance('audit');
        }

        return model(AuditLogModel::class);
    }

    public static function demo(bool $getShared = true): DemoService
    {
        if ($getShared) {
            return static::getSharedInstance('demo');
        }

        return new DemoService(
            static::monitorConfig(),
            static::watcherService(),
            model(MonitoredFileModel::class),
            model(NotificationTargetModel::class),
            static::pathGuard(),
            static::jsonLogger(),
        );
    }
}

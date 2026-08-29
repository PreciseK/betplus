<?php

declare(strict_types=1);

namespace BlackRed\Bootstrap;

use BlackRed\Auth\AuthtokenVerifier;
use BlackRed\Auth\ForgotPasswordService;
use BlackRed\Auth\OtpService;
use BlackRed\Auth\PasswordChangeService;
use BlackRed\Auth\PasswordHasher;
use BlackRed\Auth\RateLimiter;
use BlackRed\Auth\SessionManager;
use BlackRed\Auth\SignupService;
use BlackRed\Database\Connection;
use BlackRed\Http\Controllers\AuthController;
use BlackRed\Http\Controllers\CallbackController;
use BlackRed\Http\Controllers\DepositController;
use BlackRed\Http\Controllers\HealthController;
use BlackRed\Http\Controllers\MeController;
use BlackRed\Http\Controllers\SignupController;
use BlackRed\Http\Controllers\TransactionsController;
use BlackRed\Http\Controllers\GameController;
use BlackRed\Http\Controllers\StakesController;
use BlackRed\Http\Controllers\WithdrawalController;
use BlackRed\Http\Middleware\AuthMiddleware;
use BlackRed\Http\Middleware\ErrorHandler;
use BlackRed\Http\Middleware\HttpException;
use BlackRed\Http\Middleware\RateLimitMiddleware;
use BlackRed\Http\Middleware\RequestLogger;
use BlackRed\Http\Middleware\SecurityHeaders;
use BlackRed\Http\Pipeline;
use BlackRed\Http\Request;
use BlackRed\Http\Response;
use BlackRed\Http\Router;
use BlackRed\Integrations\AnmClient;
use BlackRed\Integrations\HubtelSmsClient;
use BlackRed\Logging\Logger;
use BlackRed\Sms\SmsService;
use BlackRed\Wallet\DepositService;
use BlackRed\Wallet\TransactionsService;
use BlackRed\Wallet\GameEngineService;
use BlackRed\Wallet\StakesService;
use BlackRed\Config\SystemConfigService;
use BlackRed\Wallet\WithdrawalService;

/**
 * Application bootstrap.
 *
 * Lifecycle:
 *   1. Load .env config
 *   2. Register services
 *   3. Build request from globals
 *   4. Register routes
 *   5. Dispatch through middleware pipeline
 *   6. Send response
 *
 * Single source of truth for app wiring. To add a new service, register it in
 * registerServices(). To add a new route, add it to registerRoutes().
 */
final class App
{
    private Container $container;
    private Config $config;

    public function __construct(private readonly string $basePath)
    {
        $this->config = new Config($basePath);
        $this->container = new Container();
    }

    public function run(): void
    {
        $this->config->load();
        date_default_timezone_set($this->config->string('APP_TIMEZONE', 'UTC'));

        $this->registerServices();

        $basePath = rtrim($this->config->string('APP_BASE_PATH', ''), '/');
        $request = Request::fromGlobals($basePath);

        /** @var Router $router */
        $router = $this->container->get(Router::class);
        $this->registerRoutes($router);

        $response = $this->dispatch($request, $router);
        $response->send();
    }

    private function registerServices(): void
    {
        $c = $this->container;
        $config = $this->config;

        // Singletons / primitives
        $c->instance(Config::class, $config);
        $c->set(Logger::class, fn() => new Logger($config));
        $c->set(Connection::class, fn(Container $c) => new Connection($c->get(Config::class)));
        $c->set(Router::class, fn() => new Router());

        // Auth services
        $c->set(PasswordHasher::class, fn(Container $c) =>
            new PasswordHasher($c->get(Config::class)));
        $c->set(PasswordChangeService::class, fn(Container $c) =>
            new PasswordChangeService(
                $c->get(Connection::class),
                $c->get(PasswordHasher::class),
                $c->get(Logger::class),
            ));
        $c->set(ForgotPasswordService::class, fn(Container $c) =>
            new ForgotPasswordService(
                $c->get(Connection::class),
                $c->get(PasswordHasher::class),
                $c->get(AuthtokenVerifier::class),
                $c->get(SessionManager::class),
                $c->get(Logger::class),
                $c->get(OtpService::class),
            ));
        $c->set(AuthtokenVerifier::class, fn(Container $c) =>
            new AuthtokenVerifier($c->get(Connection::class)));
        $c->set(SessionManager::class, fn(Container $c) =>
            new SessionManager($c->get(Connection::class), $c->get(Config::class)));
        $c->set(RateLimiter::class, fn(Container $c) =>
            new RateLimiter($c->get(Connection::class)));

        // Integrations
        $c->set(AnmClient::class, fn(Container $c) =>
            new AnmClient($c->get(Config::class), $c->get(Connection::class), $c->get(Logger::class)));
        $c->set(HubtelSmsClient::class, fn(Container $c) =>
            new HubtelSmsClient($c->get(Config::class), $c->get(Logger::class)));

        // SMS
        $c->set(SmsService::class, fn(Container $c) =>
            new SmsService(
                $c->get(Connection::class),
                $c->get(HubtelSmsClient::class),
                $c->get(Logger::class),
            ));

        // OTP issuing (writes to authtoken + smsLog)
        $c->set(OtpService::class, fn(Container $c) =>
            new OtpService(
                $c->get(Connection::class),
                $c->get(SmsService::class),
                $c->get(Logger::class),
            ));

        // Signup orchestrator
        $c->set(SignupService::class, fn(Container $c) =>
            new SignupService(
                $c->get(Connection::class),
                $c->get(AnmClient::class),
                $c->get(AuthtokenVerifier::class),
                $c->get(PasswordHasher::class),
                $c->get(Logger::class),
                $c->get(OtpService::class),
            ));

        // Global middleware
        $c->set(ErrorHandler::class, fn(Container $c) =>
            new ErrorHandler($c->get(Logger::class), $config->isDebug()));
        $c->set(RequestLogger::class, fn(Container $c) =>
            new RequestLogger($c->get(Logger::class)));
        $c->set(SecurityHeaders::class, fn() =>
            new SecurityHeaders($config->isProduction()));

        // Per-route middleware
        $c->set('auth.required', fn(Container $c) =>
            new AuthMiddleware(
                $c->get(SessionManager::class),
                $c->get(Config::class),
                AuthMiddleware::MODE_REQUIRED,
            ));

        // Rate-limit middleware factories — one per endpoint with its own limit
        $c->set('ratelimit.login', fn(Container $c) =>
            new RateLimitMiddleware(
                $c->get(RateLimiter::class),
                'auth.login',
                $config->int('RATE_LIMIT_LOGIN_PER_MIN', 5),
            ));
        $c->set('ratelimit.signup', fn(Container $c) =>
            new RateLimitMiddleware(
                $c->get(RateLimiter::class),
                'auth.signup',
                $config->int('RATE_LIMIT_SIGNUP_PER_MIN', 3),
            ));
        $c->set('ratelimit.signup_lookup', fn(Container $c) =>
            // Lookup hits ANM and costs us money — rate limit harder than other signup steps
            new RateLimitMiddleware(
                $c->get(RateLimiter::class),
                'auth.signup_lookup',
                $config->int('RATE_LIMIT_SIGNUP_LOOKUP_PER_MIN', 5),
            ));
        $c->set('ratelimit.general', fn(Container $c) =>
            new RateLimitMiddleware(
                $c->get(RateLimiter::class),
                'general',
                $config->int('RATE_LIMIT_GENERAL_PER_MIN', 60),
            ));
        $c->set('ratelimit.password_change', fn(Container $c) =>
            // Tighter than general: 3 attempts/min protects against a stolen
            // session being used to brute-force the current password.
            new RateLimitMiddleware(
                $c->get(RateLimiter::class),
                'auth.password_change',
                $config->int('RATE_LIMIT_PASSWORD_CHANGE_PER_MIN', 3),
            ));
        $c->set('ratelimit.forgot_lookup', fn(Container $c) =>
            // Generous — this is just acknowledging the start of a flow.
            new RateLimitMiddleware(
                $c->get(RateLimiter::class),
                'auth.forgot_lookup',
                $config->int('RATE_LIMIT_FORGOT_LOOKUP_PER_MIN', 5),
            ));
        $c->set('ratelimit.forgot_reset', fn(Container $c) =>
            // Strict: 5 attempts/min protects against brute-forcing the
            // 4-char USSD code (only ~1.6M combinations).
            new RateLimitMiddleware(
                $c->get(RateLimiter::class),
                'auth.forgot_reset',
                $config->int('RATE_LIMIT_FORGOT_RESET_PER_MIN', 5),
            ));
        $c->set('ratelimit.play', fn(Container $c) =>
            // Game endpoint: 30/min is plenty for a person tapping through
            // the wizard. Anything beyond is scripted abuse.
            new RateLimitMiddleware(
                $c->get(RateLimiter::class),
                'game.play',
                $config->int('RATE_LIMIT_PLAY_PER_MIN', 30),
            ));

        // Controllers
        $c->set(HealthController::class, fn(Container $c) =>
            new HealthController($c->get(Connection::class), $c->get(Config::class)));
        $c->set(SignupController::class, fn(Container $c) =>
            new SignupController(
                $c->get(SignupService::class),
                $c->get(SessionManager::class),
                $c->get(Config::class),
                $c->get(Logger::class),
            ));
        $c->set(AuthController::class, fn(Container $c) =>
            new AuthController(
                $c->get(Connection::class),
                $c->get(Config::class),
                $c->get(PasswordHasher::class),
                $c->get(PasswordChangeService::class),
                $c->get(ForgotPasswordService::class),
                $c->get(SessionManager::class),
                $c->get(RateLimiter::class),
                $c->get(Logger::class),
            ));
        $c->set(MeController::class, fn(Container $c) =>
            new MeController($c->get(Connection::class)));

        $c->set(TransactionsService::class, fn(Container $c) =>
            new TransactionsService(
                $c->get(Connection::class),
                $c->get(Logger::class),
            ));
        $c->set(TransactionsController::class, fn(Container $c) =>
            new TransactionsController($c->get(TransactionsService::class)));

        // Phase 5 — game engine
        $c->set(SystemConfigService::class, fn(Container $c) =>
            new SystemConfigService($c->get(Connection::class)));
        $c->set(GameEngineService::class, fn(Container $c) =>
            new GameEngineService(
                $c->get(Connection::class),
                $c->get(SystemConfigService::class),
                $c->get(Logger::class),
            ));
        $c->set(GameController::class, fn(Container $c) =>
            new GameController(
                $c->get(GameEngineService::class),
                $c->get(Logger::class),
            ));

        // Phase 5b — Stake history (read-only feed of gameRound)
        $c->set(StakesService::class, fn(Container $c) =>
            new StakesService(
                $c->get(Connection::class),
                $c->get(Logger::class),
            ));
        $c->set(StakesController::class, fn(Container $c) =>
            new StakesController($c->get(StakesService::class)));

        $c->set(DepositService::class, function (Container $c) use ($config) {
            // Build absolute callback URL from configured base. We don't
            // build it from request URL because the public URL might be
            // different from what the app sees behind a proxy.
            $callbackBase = rtrim($config->string('APP_PUBLIC_URL', ''), '/');
            $basePath     = rtrim($config->string('APP_BASE_PATH', ''), '/');
            $callbackUrl  = $callbackBase . $basePath . '/api/callbacks/momo';
            return new DepositService(
                $c->get(Connection::class),
                $c->get(AnmClient::class),
                $c->get(Logger::class),
                $callbackUrl,
                $config->string('DEPOSIT_REFERENCE', 'BlackRed Sika Bum'),
                $config->int('DEPOSIT_FEE_BPS', 100),
            );
        });
        $c->set(DepositController::class, fn(Container $c) =>
            new DepositController(
                $c->get(DepositService::class),
                $c->get(Config::class),
                $c->get(Logger::class),
            ));
        $c->set(WithdrawalService::class, function (Container $c) use ($config) {
            $callbackBase = rtrim($config->string('APP_PUBLIC_URL', ''), '/');
            $basePath     = rtrim($config->string('APP_BASE_PATH', ''), '/');
            $callbackUrl  = $callbackBase . $basePath . '/api/callbacks/momo';
            return new WithdrawalService(
                $c->get(Connection::class),
                $c->get(AnmClient::class),
                $c->get(Logger::class),
                $callbackUrl,
                $config->string('DEPOSIT_REFERENCE', 'BlackRed Sika Bum'),
                $config->int('WITHDRAWAL_FEE_BPS', 50),
            );
        });
        $c->set(WithdrawalController::class, fn(Container $c) =>
            new WithdrawalController(
                $c->get(WithdrawalService::class),
                $c->get(Config::class),
                $c->get(Logger::class),
            ));
        $c->set(CallbackController::class, fn(Container $c) =>
            new CallbackController(
                $c->get(DepositService::class),
                $c->get(WithdrawalService::class),
                $c->get(Config::class),
                $c->get(Logger::class),
            ));
    }

    private function registerRoutes(Router $router): void
    {
        // Public — no auth required
        $router->get('/api/health', [
            'class' => HealthController::class,
            'method' => 'check',
        ]);

        // Signup wizard — three steps, all rate limited
        $router->post('/api/auth/signup/lookup', [
            'class' => SignupController::class,
            'method' => 'lookup',
        ], ['ratelimit.signup_lookup']);

        $router->post('/api/auth/signup/verify', [
            'class' => SignupController::class,
            'method' => 'verify',
        ], ['ratelimit.signup']);

        $router->post('/api/auth/signup/complete', [
            'class' => SignupController::class,
            'method' => 'complete',
        ], ['ratelimit.signup']);

        // Login / logout
        $router->post('/api/auth/login', [
            'class' => AuthController::class,
            'method' => 'login',
        ], ['ratelimit.login']);

        $router->post('/api/auth/logout', [
            'class' => AuthController::class,
            'method' => 'logout',
        ], ['auth.required']);

        $router->post('/api/auth/change-password', [
            'class' => AuthController::class,
            'method' => 'changePassword',
        ], ['auth.required', 'ratelimit.password_change']);

        // Forgot password — public, USSD-based reset flow.
        $router->post('/api/auth/forgot/lookup', [
            'class' => AuthController::class,
            'method' => 'forgotLookup',
        ], ['ratelimit.forgot_lookup']);

        $router->post('/api/auth/forgot/reset', [
            'class' => AuthController::class,
            'method' => 'forgotReset',
        ], ['ratelimit.forgot_reset']);

        // Authenticated endpoints
        $router->get('/api/me', [
            'class' => MeController::class,
            'method' => 'show',
        ], ['auth.required', 'ratelimit.general']);

        // Transactions feed — unified deposits + withdrawals history
        $router->get('/api/transactions', [
            'class' => TransactionsController::class,
            'method' => 'index',
        ], ['auth.required', 'ratelimit.general']);

        // Phase 3 — Deposits
        $router->post('/api/deposits', [
            'class' => DepositController::class,
            'method' => 'create',
        ], ['auth.required', 'ratelimit.general']);
        $router->get('/api/deposits/:id', [
            'class' => DepositController::class,
            'method' => 'get',
        ], ['auth.required']);
        $router->post('/api/deposits/:id/verify', [
            'class' => DepositController::class,
            'method' => 'verify',
        ], ['auth.required']);

        // Phase 4 — Withdrawals
        $router->post('/api/withdrawals', [
            'class' => WithdrawalController::class,
            'method' => 'create',
        ], ['auth.required', 'ratelimit.general']);
        $router->get('/api/withdrawals/:id', [
            'class' => WithdrawalController::class,
            'method' => 'get',
        ], ['auth.required']);
        $router->post('/api/withdrawals/:id/verify', [
            'class' => WithdrawalController::class,
            'method' => 'verify',
        ], ['auth.required']);

        // Phase 5 — Game engine. Single endpoint: client locks in 12 cards
        // + game type + picks + stake; engine atomically debits, decides
        // outcome, credits payout if any, returns drawn cards + result.
        $router->post('/api/game/play', [
            'class' => GameController::class,
            'method' => 'play',
        ], ['auth.required', 'ratelimit.play']);

        // Phase 5b — Stake history (read-only)
        $router->get('/api/stakes', [
            'class' => StakesController::class,
            'method' => 'index',
        ], ['auth.required', 'ratelimit.general']);

        // ANM webhook — public, no auth, no rate limit. Single endpoint
        // dispatches to deposit OR withdrawal based on which exttrid the
        // body carries. CallbackController handles the lookup.
        $router->post('/api/callbacks/momo', [
            'class' => CallbackController::class,
            'method' => 'momo',
        ], []);
    }

    private function dispatch(Request $request, Router $router): Response
    {
        $globalMiddleware = [
            $this->container->get(ErrorHandler::class),
            $this->container->get(RequestLogger::class),
            $this->container->get(SecurityHeaders::class),
        ];

        $match = $router->match($request->method, $request->path);

        if ($match === null) {
            $finalHandler = function (Request $req) use ($router): Response {
                if ($router->pathExists($req->path)) {
                    throw HttpException::methodNotAllowed();
                }
                throw HttpException::notFound();
            };
            return (new Pipeline($globalMiddleware, $finalHandler))->handle($request);
        }

        $routeMiddleware = [];
        foreach ($match['middleware'] as $serviceId) {
            $routeMiddleware[] = $this->container->get($serviceId);
        }

        $handler = $match['handler'];
        $params = $match['params'];
        $finalHandler = function (Request $req) use ($handler, $params): Response {
            $controller = $this->container->get($handler['class']);
            $method = $handler['method'];
            if (!method_exists($controller, $method)) {
                throw new \RuntimeException(
                    "Controller {$handler['class']} has no method {$method}"
                );
            }
            return $controller->$method($req, $params);
        };

        $allMiddleware = array_merge($globalMiddleware, $routeMiddleware);
        return (new Pipeline($allMiddleware, $finalHandler))->handle($request);
    }
}
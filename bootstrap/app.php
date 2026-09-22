<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /** The application only serves JSON, so errors must not fall back to HTML. */
        $exceptions->shouldRenderJsonWhen(fn () => true);

        /** The default 404 body would name the model class. */
        $exceptions->render(fn (NotFoundHttpException $e) => response()->json(['message' => 'Not Found.'], 404));

        /**
         * A 409 is a normal answer, so it keeps the `{"message": ...}` shape even with
         * APP_DEBUG on. Registered after the 404 above, which is an HttpException too;
         * other statuses fall through and stay debuggable.
         */
        $exceptions->render(fn (HttpException $e) => $e->getStatusCode() === 409
            ? response()->json(['message' => $e->getMessage()], 409)
            : null);
    })->create();

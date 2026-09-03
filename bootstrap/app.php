<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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

        /**
         * The default 404 body names the model class ("No query results for model
         * [App\Models\Import] 999"), an implementation detail. One neutral message for a
         * missing record and for an unknown route alike.
         */
        $exceptions->render(fn (NotFoundHttpException $e) => response()->json(['message' => 'Not Found.'], 404));
    })->create();

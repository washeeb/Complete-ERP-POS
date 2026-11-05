<?php

use App\Support\PlainError;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

function sqlError(string $state, string $text): QueryException
{
    $pdo = new PDOException($text);
    $pdo->errorInfo = [$state, 7, $text];

    return new QueryException('pgsql', 'insert into "suppliers" ("name") values (?)', ['SECRET NAME'], $pdo);
}

it('says what failed, in plain words, without the values', function (string $state, string $text, string $says) {
    $words = PlainError::explain(sqlError($state, $text));
    expect($words)->toContain($says)->not->toContain('SECRET')->not->toContain('insert into');
})->with([
    'empty' => ['23502', 'null value in column "payment_terms_days" of relation "suppliers" violates not-null constraint', 'Payment terms days was left empty, and a supplier cannot be saved without it'],
    'duplicate' => ['23505', 'duplicate key value violates unique constraint "x" DETAIL: Key (tenant_id, code)=(1, SECRET) already exists.', 'Code is already used'],
    'too long' => ['22001', 'value too long for type character varying(20)', 'at most 20 characters'],
    'busy' => ['40P01', 'deadlock detected', 'Someone else was changing the same thing'],
    'update' => ['42703', 'column "x" does not exist', 'an update may not have finished'],
]);

it('names the fault in the program', function () {
    expect(PlainError::explain(new LogicException('Document numbers are taken inside the document transaction.')))->toContain('Document numbers are taken inside')
        ->and(PlainError::explain(new TypeError('secret value')))->toContain('TypeError in PlainErrorTest.php')->not->toContain('secret value');
});

it('answers a back-office request with the problem and its reference, and leaves other errors alone', function () {
    config(['app.debug' => false]);
    $request = Request::create('/livewire/update', 'POST', server: ['HTTP_X_LIVEWIRE' => '1']);
    $request->attributes->set('request_id', 'REF-1');
    $handler = app(ExceptionHandler::class);

    $body = json_decode($handler->render($request, sqlError('23502', 'null value in column "phone" of relation "customers"'))->getContent(), true);
    expect($body['reference'])->toBe('REF-1')->and($body['problem'])->toContain('Phone was left empty');

    expect($handler->render($request, new HttpException(503))->getStatusCode())->toBe(503)
        ->and($handler->render(Request::create('/admin', 'GET'), new RuntimeException('secret'))->getContent())->not->toContain('RuntimeException in');
});

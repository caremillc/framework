<?php

declare(strict_types=1);

namespace Flint\Http;

use Flint\Http\Output\ResponseOutput;

final class ResponseEmitter
{
    public function __construct(
        private readonly ResponseOutput $output,
        private readonly ResponsePreparer $preparer = new ResponsePreparer(),
    ) {
    }

    public function emit(
        Response $response,
        bool $headRequest = false,
    ): void {
        $prepared = $this->preparer->prepare($response);

        $this->output->begin();

        foreach ($prepared->headers()->all() as $header) {
            foreach ($header['values'] as $index => $value) {
                $this->output->writeHeader(
                    $header['name'],
                    $value,
                    replace: $index === 0,
                );
            }
        }

        $this->output->writeStatus($prepared->status());

        if (!$headRequest && $prepared->body() !== '') {
            $this->output->writeBody($prepared->body());
        }
    }
}

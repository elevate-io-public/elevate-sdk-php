<?php

declare(strict_types=1);

namespace Elevate\SystemTests\Support;

use cebe\openapi\spec\Operation as OperationSpecification;
use League\OpenAPIValidation\PSR7\OperationAddress;

final readonly class Operation
{
    /**
     * @param OperationAddress $address the path template and the method in lower case
     */
    public function __construct(
        public string $id,
        public OperationAddress $address,
        public OperationSpecification $specification,
    ) {
    }
}

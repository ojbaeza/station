<?php

declare(strict_types=1);

namespace Station\Alerts\Evaluators;

use Station\DTOs\AlertEvaluation;
use Station\DTOs\AlertRule;

interface AlertEvaluatorInterface
{
    public function evaluate(AlertRule $rule): ?AlertEvaluation;
}

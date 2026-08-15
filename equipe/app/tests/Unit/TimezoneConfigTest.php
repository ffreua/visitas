<?php

namespace Tests\Unit;

use Tests\TestCase;

class TimezoneConfigTest extends TestCase
{
    /**
     * Regressão: config/app.php tinha 'timezone' hardcoded como 'UTC',
     * ignorando silenciosamente APP_TIMEZONE do .env. Isso fazia "hoje"
     * (round_date, filtros de dashboard) ficar 3h adiantado em relação ao
     * hospital, com maior impacto entre 21h e 00h BRT.
     */
    public function test_app_timezone_is_read_from_env_not_hardcoded(): void
    {
        $this->assertSame('America/Sao_Paulo', config('app.timezone'));
        $this->assertSame('America/Sao_Paulo', date_default_timezone_get());
    }
}

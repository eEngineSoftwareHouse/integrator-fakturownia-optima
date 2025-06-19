<?php
namespace Integrator;

class MedooMssql extends \Medoo\Medoo
{
    /**
     * Override table quoting to support schema-prefixed tables in MSSQL.
     */
    public function tableQuote(string $table): string
    {
        return $this->prefix . $table;
    }
}

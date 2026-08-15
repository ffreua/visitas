<?php

namespace App\Exceptions;

use Exception;

/**
 * Lançada quando um Admission é salvo com uma `version` desatualizada,
 * indicando que outro usuário já alterou o registro (optimistic locking).
 */
class StaleAdmissionException extends Exception
{
    public function __construct()
    {
        parent::__construct('Este atendimento foi atualizado por outro usuário. Recarregue os dados antes de salvar.');
    }
}

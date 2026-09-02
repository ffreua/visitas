<?php

namespace App\Exceptions;

use Exception;

/**
 * Última linha de defesa contra alteração de prontuário já confirmado —
 * lançada pelo próprio model (Patient::booted), não só pela validação da
 * request, para que nenhum caminho de escrita futuro (comando, seeder,
 * import) contorne a regra por descuido.
 */
class ConfirmedMedicalRecordException extends Exception
{
    protected $message = 'O número de prontuário já foi confirmado e não pode mais ser alterado.';
}

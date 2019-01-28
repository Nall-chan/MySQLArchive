<?php

declare(strict_types = 1);

/**
 * Trait mit Hilfsfunktionen für Variablen.
 */
trait ReferenceHelper
{
    protected function RegisterReference($id)
    {
        if (method_exists('IPSModule', 'RegisterReference')) {
            parent::RegisterReference($id);
        }
    }
}

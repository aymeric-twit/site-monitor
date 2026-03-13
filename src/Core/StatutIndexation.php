<?php

declare(strict_types=1);

namespace SiteMonitor\Core;

enum StatutIndexation: string
{
    case Indexable = 'indexable';
    case NonIndexable = 'non_indexable';
    case Contradictoire = 'contradictoire';
    case Erreur = 'erreur';
}

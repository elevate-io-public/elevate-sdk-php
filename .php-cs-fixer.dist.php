<?php

declare(strict_types=1);

return (new PhpCsFixer\Config())
    // Permits declare_strict_types and @Symfony:risky: php-cs-fixer calls a rule "risky" when its fix can change behaviour, and refuses to run one without this.
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
    ])
    ->setFinder(
        (new PhpCsFixer\Finder())
            ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/examples', __DIR__.'/generator', __DIR__.'/bin'])
            ->append([__FILE__])
    );

<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('docs:index {--fresh : Recreate the documentation index from scratch}')]
#[Description('Rebuild the MoneyUnify Switch documentation index.')]
class DocsIndexCommand extends Command
{
    /**
     * Execute the console command.
     *
     * Thin, MoneyUnify-branded alias over the underlying documentation
     * indexer so operators never need to reference the vendor command.
     */
    public function handle(): int
    {
        return $this->call('prezet:index', [
            '--fresh' => $this->option('fresh'),
        ]);
    }
}

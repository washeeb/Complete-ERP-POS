<?php

use Symfony\Component\Finder\Finder;

it('never glues a Blade directive to a word, which would print the code on the page instead of running it', function () {
    // Blade only reads "@if" as a directive after a non-word character: "awarded@if (...)" shows on screen as typed.
    $glued = [];
    foreach (Finder::create()->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
        foreach (preg_split('/\R/', $file->getContents()) as $n => $line) {
            if (preg_match('/\w@(if|elseif|else|endif|unless|endunless|isset|endisset|foreach|endforeach|forelse|empty|endforelse|php|endphp)\b/', $line)) {
                $glued[] = $file->getRelativePathname().':'.($n + 1);
            }
        }
    }

    expect($glued)->toBe([]); // put a space before the directive, or use {{ $x ? '…' : '' }}
});

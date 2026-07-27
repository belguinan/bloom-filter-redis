<?php

namespace App\Http\Controllers;

use App\Services\EmailLookup;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EmailVerificationController
{
    public function index(): View
    {
        return view('verification');
    }

    public function verify(Request $request, EmailLookup $lookup): View
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $validated = $request->validate([
            'engine' => ['required', 'in:redis,mysql'],
            'email' => ['required', 'email:rfc', 'max:254'],
        ], [
            'engine.required' => 'Choisissez un moteur.',
            'engine.in' => 'Le moteur choisi est invalide.',
            'email.required' => 'Saisissez une adresse email.',
            'email.email' => 'Saisissez une adresse email valide.',
            'email.max' => 'L’adresse email est trop longue.',
        ]);

        $lookup->prepare($validated['engine']);
        $startedAt = hrtime(true);
        $found = $lookup->exists($validated['engine'], $validated['email']);
        $latencyMs = (hrtime(true) - $startedAt) / 1_000_000;

        return view('verification', [
            'engine' => $validated['engine'],
            'email' => $validated['email'],
            'latencyMs' => $latencyMs,
            'result' => $this->result($validated['engine'], $found),
        ]);
    }

    private function result(string $engine, bool $found): array
    {
        return match ($engine.':'.(int) $found) {
            'mysql:1' => [
                'tone' => 'positive',
                'title' => 'Email trouvé',
                'detail' => 'Réponse exacte fournie par l’index MySQL.',
            ],
            'mysql:0' => [
                'tone' => 'negative',
                'title' => 'Email absent',
                'detail' => 'Réponse exacte fournie par l’index MySQL.',
            ],
            'redis:1' => [
                'tone' => 'probable',
                'title' => 'Email probablement présent',
                'detail' => 'Un faux positif reste possible avec le Bloom Filter.',
            ],
            default => [
                'tone' => 'negative',
                'title' => 'Email certainement absent',
                'detail' => 'Un Bloom Filter correctement construit ne produit pas de faux négatif.',
            ],
        };
    }
}

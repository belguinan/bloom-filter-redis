<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vérification d’email</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <main class="page">
        <section class="card">
            <div class="heading">
                <span class="eyebrow">Benchmark d’appartenance</span>
                <h1>Vérification d’email</h1>
                <p>Choisissez un moteur et mesurez sa réponse sur le même jeu d’un million d’adresses.</p>
            </div>

            <form method="post" action="{{ route('verification.verify') }}" data-verification-form>
                @csrf

                <fieldset>
                    <legend>Moteur de recherche</legend>
                    <div class="engine-switch">
                        <label>
                            <input type="radio" name="engine" value="redis" @checked(($engine ?? old('engine', 'redis')) === 'redis')>
                            <span>Redis Bloom</span>
                        </label>
                        <label>
                            <input type="radio" name="engine" value="mysql" @checked(($engine ?? old('engine')) === 'mysql')>
                            <span>MySQL</span>
                        </label>
                    </div>
                </fieldset>

                <label class="field" for="email">
                    <span>Adresse email</span>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        value="{{ $email ?? old('email') }}"
                        placeholder="student0000001@example.test"
                        autocomplete="off"
                        required
                    >
                </label>

                @error('email')
                    <p class="validation">{{ $message }}</p>
                @enderror

                @error('engine')
                    <p class="validation">{{ $message }}</p>
                @enderror

                <div class="samples">
                    <span>Exemples :</span>
                    <button type="button" data-email="student0000001@example.test">présent</button>
                    <button type="button" data-email="unknown0000001@example.test">absent</button>
                </div>

                <button class="submit" type="submit">
                    <span>Vérifier</span>
                </button>
            </form>

            @isset($result)
                <section class="result result--{{ $result['tone'] }}" aria-live="polite">
                    <div>
                        <span class="result-engine">{{ $engine === 'redis' ? 'Redis Bloom Filter' : 'MySQL indexé' }}</span>
                        <h2>{{ $result['title'] }}</h2>
                        <p>{{ $result['detail'] }}</p>
                    </div>
                    <div class="latency">
                        <span>Latence serveur</span>
                        <strong>{{ number_format($latencyMs, 3, ',', ' ') }} ms</strong>
                    </div>
                </section>
            @endisset

            <div class="semantics">
                <p><strong>MySQL :</strong> réponse exacte.</p>
                <p><strong>Redis Bloom :</strong> absence certaine ou présence probable.</p>
            </div>
        </section>
    </main>
</body>
</html>

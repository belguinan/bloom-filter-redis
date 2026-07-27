# Redis Bloom Filter pour la vérification d’emails

## Étude comparative avec une table MySQL indexée

Réalisé par :

- Noureddine Belguinan
- Khalid Jebli
- Taghouni Abdellah

## Cloner le projet

```bash
git clone https://github.com/belguinan/bloom-filter-redis.git
cd bloom-filter-redis
```

## 1. Préparer l’application

```bash
composer install
npm install
npm run build
cp .env.example .env
php artisan key:generate
```

Configurer MySQL et Redis dans `.env`, puis créer la base si nécessaire :

```bash
mysql -u root -p -e "CREATE DATABASE email_bloom CHARACTER SET ascii COLLATE ascii_bin;"
redis-cli ping
php artisan migrate
```

## 2. Charger les données

Arrêter le serveur Laravel avant une reconstruction, puis exécuter :

```bash
php artisan emails:load --count=1000000 --error-rate=0.01 --batch=1000
```

## 3. Lancer le formulaire

```bash
php artisan serve
```

Ouvrir <http://127.0.0.1:8000>.

## 4. Scénario de démonstration

### Redis, adresse présente

Sélectionner `Redis Bloom` et saisir :

```text
student0000001@example.test
```

Résultat attendu : « Email probablement présent ». Expliquer qu’un positif peut être faux.

### Redis, adresse absente

Saisir :

```text
unknown0000001@example.test
```

Résultat attendu dans le cas général : « Email certainement absent ». Un bit à zéro suffit pour conclure.

### MySQL, mêmes valeurs

Basculer vers `MySQL`, puis répéter les deux recherches. MySQL retourne une réponse exacte à l’aide de la clé primaire.

La latence affichée correspond uniquement au lookup côté serveur. Elle sert à illustrer une requête; les conclusions reposent sur le benchmark multi-exécutions.

## 5. Rejouer le benchmark

```bash
php artisan emails:benchmark \
  --queries=10000 \
  --runs=5 \
  --warmup=1000 \
  --quality=100000
```

Le protocole :

- utilise les mêmes séquences pour les deux moteurs ;
- alterne l’ordre Redis/MySQL entre les cinq exécutions ;
- chauffe chaque moteur avec les mêmes volumes ;
- sépare les charges présentes, absentes et 50/50 ;
- exclut HTTP et le rendu du navigateur ;
- mesure moyenne, p50, p95, p99 et requêtes par seconde ;
- contrôle 100 000 présences et 100 000 absences supplémentaires ;
- mesure `MEMORY USAGE` Redis et `DATA_LENGTH + INDEX_LENGTH` MySQL.

Consulter :

```bash
less results/benchmark.md
```

## 6. Tests

Tests rapides :

```bash
php artisan test
vendor/bin/pint --test
npm run build
```

Tests avec les vrais services configurés dans `.env` :

```bash
RUN_INTEGRATION_TESTS=1 php artisan test --env=local
```

## 7. Résultats actuels

| Indicateur             |      Redis Bloom |             MySQL |
| ---------------------- | ---------------: | ----------------: |
| p50, charge 50/50      |         0,028 ms |          0,115 ms |
| Débit, charge 50/50    |     33 359 req/s |       8 292 req/s |
| Stockage mesuré        | 2 130 272 octets | 51 003 392 octets |
| Réponse positive       |         probable |            exacte |
| Faux négatifs observés |      0 / 100 000 |                 0 |
| Faux positifs observés |    998 / 100 000 |                 0 |

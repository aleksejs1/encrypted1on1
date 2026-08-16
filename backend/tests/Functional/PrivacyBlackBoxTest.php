<?php

namespace App\Tests\Functional;

use App\Entity\ActivationToken;
use App\Tests\Support\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A genuine black-box privacy test — real HTTP requests, real crypto, real SQLite file —
 * complementing SerializationBoundaryTest's structural check ("does this field carry a
 * serialization Group") with a behavioral one: given a fully populated anketa, is any
 * plaintext actually recoverable, via the API or directly from the database, without a
 * participant's private key?
 *
 * Uses PHP's own ext-sodium (confirmed installed, and functionally identical to the
 * frontend's model: crypto_box_keypair/_seal/_seal_open, crypto_aead_xchacha20poly1305_ietf
 * encrypt/decrypt) rather than opaque placeholder strings — this runs as an ordinary
 * PHPUnit functional test, no Node script, no browser, while still being a real crypto
 * round trip. Only the content-confidentiality primitives are replicated here, not the
 * full password-derivation chain (a different claim, already covered by encryption.md and
 * the frontend's password.test.ts) — authKey/encryptedPrivateKey stay opaque placeholders.
 */
class PrivacyBlackBoxTest extends ApiTestCase
{
    public function testNoPlaintextLeaksAnywhereInTheDatabase(): void
    {
        $scenario = $this->buildFullyPopulatedAnketa();

        foreach ($scenario['encryptedMarkers'] as $label => $marker) {
            self::assertNull($this->findMarkerInDatabase($marker), "encrypted content ({$label}) must not appear anywhere in the database");
        }

        // The one deliberate plaintext exception (a goal's title) — confirms the scan
        // mechanism itself genuinely works, not vacuously passing everything.
        self::assertNotNull($this->findMarkerInDatabase($scenario['goalTitleMarker']), 'goal title is meant to be plaintext — the scan should find it, proving it actually scans');
    }

    public function testApiResponsesNeverContainPlaintextExceptTheDocumentedGoalException(): void
    {
        $scenario = $this->buildFullyPopulatedAnketa();

        $this->jsonRequest($scenario['employeeClient'], 'GET', "/api/anketas/{$scenario['anketaId']}");
        $rawResponse = (string) $scenario['employeeClient']->getResponse()->getContent();

        foreach ($scenario['encryptedMarkers'] as $label => $marker) {
            self::assertStringNotContainsString($marker, $rawResponse, "encrypted content ({$label}) must not appear in the raw API response");
        }
        self::assertStringContainsString($scenario['goalTitleMarker'], $rawResponse, 'goal title is meant to be visible via the API');
    }

    public function testANonParticipantIsRejectedAndNeverSeesAnyContent(): void
    {
        $scenario = $this->buildFullyPopulatedAnketa();

        $result = $this->jsonRequest($scenario['strangerClient'], 'GET', "/api/anketas/{$scenario['anketaId']}");
        $rawResponse = (string) $scenario['strangerClient']->getResponse()->getContent();

        self::assertSame(403, $result['status']);
        foreach ([...$scenario['encryptedMarkers'], $scenario['goalTitleMarker']] as $marker) {
            self::assertStringNotContainsString($marker, $rawResponse);
        }
    }

    public function testOnlyTheCorrectPrivateKeyCanRecoverThePublishedPlaintext(): void
    {
        $scenario = $this->buildFullyPopulatedAnketa();

        $detail = $this->jsonRequest($scenario['employeeClient'], 'GET', "/api/anketas/{$scenario['anketaId']}")['json'];

        $anketaKey = sodium_crypto_box_seal_open(base64_decode($detail['mySealedKey']), $scenario['employeeKeypair']);
        self::assertSame($scenario['anketaKeyRaw'], $anketaKey, 'unsealing with the real private key must recover the exact original anketa key');

        self::assertSame($scenario['encryptedMarkers']['employee'], $this->decryptBlob($detail['employeeBlob'], $anketaKey));
        self::assertSame($scenario['encryptedMarkers']['manager'], $this->decryptBlob($detail['managerBlob'], $anketaKey));
        self::assertSame($scenario['encryptedMarkers']['comments'], $this->decryptBlob($detail['commentsBlob'], $anketaKey));
        self::assertSame($scenario['encryptedMarkers']['outcomes'], $this->decryptBlob($detail['outcomesBlob'], $anketaKey));
        self::assertSame($scenario['encryptedMarkers']['checkpoint'], $this->decryptBlob($detail['goalCheckpointsBlob'], $anketaKey));
    }

    public function testDecryptingWithTheWrongKeyFails(): void
    {
        $scenario = $this->buildFullyPopulatedAnketa();

        $detail = $this->jsonRequest($scenario['employeeClient'], 'GET', "/api/anketas/{$scenario['anketaId']}")['json'];

        $wrongKeypair = sodium_crypto_box_keypair();
        self::assertFalse(sodium_crypto_box_seal_open(base64_decode($detail['mySealedKey']), $wrongKeypair), 'an unrelated keypair must not be able to unseal the real anketa key');

        $wrongAeadKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        self::assertFalse($this->decryptBlob($detail['employeeBlob'], $wrongAeadKey), 'a random key must not be able to decrypt real ciphertext');
    }

    /**
     * One full, real anketa lifecycle: three real accounts (employee, manager, a
     * non-participant "stranger") with genuine X25519 keypairs, a real anketa with the
     * anketa key genuinely crypto_box_seal'ed to both real public keys, and real
     * AEAD-encrypted content in every encrypted field this app has — each with its own
     * unique random marker as the "plaintext" — plus one real Goal whose title carries
     * its own marker (the one deliberate, documented plaintext exception).
     *
     * @return array{
     *     anketaId: string, anketaKeyRaw: string,
     *     employeeClient: KernelBrowser, employeeKeypair: string,
     *     strangerClient: KernelBrowser,
     *     encryptedMarkers: array<string, string>, goalTitleMarker: string,
     * }
     */
    private function buildFullyPopulatedAnketa(): array
    {
        $label = bin2hex(random_bytes(6));
        $marker = static fn (string $tag): string => "PRIVACY-MARKER-{$label}-{$tag}";

        $employeeKeypair = sodium_crypto_box_keypair();
        $managerKeypair = sodium_crypto_box_keypair();

        $employeeClient = static::createClient();
        $employee = $this->activateUserWithRealKeypair($employeeClient, $this->uniqueEmail("privacy-{$label}-emp"), sodium_crypto_box_publickey($employeeKeypair));

        $managerClient = $this->secondClient();
        $manager = $this->activateUserWithRealKeypair($managerClient, $this->uniqueEmail("privacy-{$label}-mgr"), sodium_crypto_box_publickey($managerKeypair));

        $strangerClient = $this->secondClient();
        $this->activateUserWithRealKeypair($strangerClient, $this->uniqueEmail("privacy-{$label}-stranger"), sodium_crypto_box_publickey(sodium_crypto_box_keypair()));

        $anketaKeyRaw = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $mySealedKey = base64_encode(sodium_crypto_box_seal($anketaKeyRaw, sodium_crypto_box_publickey($employeeKeypair)));
        $counterpartSealedKey = base64_encode(sodium_crypto_box_seal($anketaKeyRaw, sodium_crypto_box_publickey($managerKeypair)));

        $created = $this->jsonRequest($employeeClient, 'POST', '/api/anketas', [
            'counterpartId' => $manager['id'],
            'myRole' => 'employee',
            'meetingDate' => (new \DateTimeImmutable('+1 day'))->format(\DateTimeImmutable::ATOM),
            'mySealedKey' => $mySealedKey,
            'counterpartSealedKey' => $counterpartSealedKey,
            'periodicityDays' => 30,
        ]);
        self::assertSame(201, $created['status']);
        $anketaId = $created['json']['id'];

        $encryptedMarkers = [
            'employee' => $marker('employee-answers'),
            'manager' => $marker('manager-answers'),
            'comments' => $marker('comments'),
            'outcomes' => $marker('outcomes'),
            'checkpoint' => $marker('checkpoint'),
        ];
        $goalTitleMarker = $marker('goal-title');

        $publishEmployee = $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/publish", [
            'blob' => $this->encryptBlob($encryptedMarkers['employee'], $anketaKeyRaw),
        ]);
        self::assertSame(200, $publishEmployee['status']);

        $publishManager = $this->jsonRequest($managerClient, 'POST', "/api/anketas/{$anketaId}/publish", [
            'blob' => $this->encryptBlob($encryptedMarkers['manager'], $anketaKeyRaw),
        ]);
        self::assertSame(200, $publishManager['status']);

        $comments = $this->jsonRequest($employeeClient, 'PUT', "/api/anketas/{$anketaId}/comments", [
            'blob' => $this->encryptBlob($encryptedMarkers['comments'], $anketaKeyRaw),
            'expectedVersion' => 0,
        ]);
        self::assertSame(200, $comments['status']);

        $outcomes = $this->jsonRequest($employeeClient, 'PUT', "/api/anketas/{$anketaId}/outcomes", [
            'blob' => $this->encryptBlob($encryptedMarkers['outcomes'], $anketaKeyRaw),
            'expectedVersion' => 0,
        ]);
        self::assertSame(200, $outcomes['status']);

        $goal = $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/goals", [
            'goalUuid' => "privacy-{$label}-goal",
            'title' => $goalTitleMarker,
        ]);
        self::assertSame(201, $goal['status']);

        $checkpoints = $this->jsonRequest($employeeClient, 'PUT', "/api/anketas/{$anketaId}/goal-checkpoints", [
            'blob' => $this->encryptBlob($encryptedMarkers['checkpoint'], $anketaKeyRaw),
            'expectedVersion' => 0,
        ]);
        self::assertSame(200, $checkpoints['status']);

        return [
            'anketaId' => $anketaId,
            'anketaKeyRaw' => $anketaKeyRaw,
            'employeeClient' => $employeeClient,
            'employeeKeypair' => $employeeKeypair,
            'strangerClient' => $strangerClient,
            'encryptedMarkers' => $encryptedMarkers,
            'goalTitleMarker' => $goalTitleMarker,
        ];
    }

    /**
     * Mirrors ApiTestCase::activateUser()'s own low-level steps, but sends a *real*
     * X25519 public key instead of a placeholder — authKey/encryptedPrivateKey stay
     * opaque placeholders, since this test is about content confidentiality, not the
     * password-derivation chain.
     *
     * @return array{id: string, email: string, isAdmin: bool}
     */
    private function activateUserWithRealKeypair(KernelBrowser $client, string $email, string $publicKeyRaw): array
    {
        [$token, $rawToken] = ActivationToken::issue($email, $this->singleCompanyProvider()->get());
        $this->entityManager()->persist($token);
        $this->entityManager()->flush();

        $result = $this->jsonRequest($client, 'POST', "/api/activation-tokens/{$rawToken}/complete", [
            'authKey' => str_repeat('a', 44),
            'publicKey' => base64_encode($publicKeyRaw),
            'encryptedPrivateKey' => str_repeat('c', 44),
            'locale' => 'en',
        ]);
        self::assertSame(200, $result['status'], 'activation should succeed in test setup: '.json_encode($result));

        /** @var array{id: string, email: string, isAdmin: bool} $json */
        $json = $result['json'];

        return $json;
    }

    private function encryptBlob(string $plaintext, string $key): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, '', $nonce, $key);

        return base64_encode($nonce.$ciphertext);
    }

    /** @return string|false */
    private function decryptBlob(string $blob, string $key)
    {
        $combined = base64_decode($blob);
        $nonce = substr($combined, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = substr($combined, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, '', $nonce, $key);
    }

    /**
     * Scans every column of every real table in the actual SQLite file for $needle,
     * dynamically — mirrors SerializationBoundaryTest's own "catch it automatically,
     * don't hand-enumerate it" philosophy, just via the DB schema instead of PHP's.
     * Table/column names come from the schema itself (sqlite_master/PRAGMA
     * table_info), never from external input, so direct interpolation is safe. No
     * per-column type filtering is needed: SQLite's dynamic typing means a LIKE
     * against a non-text column is simply always safe and never spuriously matches.
     */
    private function findMarkerInDatabase(string $needle): ?string
    {
        $connection = $this->entityManager()->getConnection();

        /** @var list<string> $tables */
        $tables = $connection->executeQuery("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchFirstColumn();

        foreach ($tables as $table) {
            /** @var list<array{name: string}> $columns */
            $columns = $connection->executeQuery(sprintf('PRAGMA table_info(%s)', $table))->fetchAllAssociative();
            foreach ($columns as $column) {
                $columnName = $column['name'];
                $count = (int) $connection->executeQuery(
                    sprintf('SELECT COUNT(*) FROM %s WHERE %s LIKE ?', $table, $columnName),
                    ['%'.$needle.'%'],
                )->fetchOne();
                if ($count > 0) {
                    return "{$table}.{$columnName}";
                }
            }
        }

        return null;
    }
}

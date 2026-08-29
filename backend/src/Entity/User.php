<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * A fully registered user: keys already generated client-side (see the
 * crypto model in the spec). Registration/invite flows are a later phase
 * and may introduce a separate pending-registration concept — this entity
 * only represents the end state.
 */
#[ORM\Entity]
#[ORM\Table(name: 'users')]
#[ApiResource(
    operations: [new GetCollection(), new Get()],
    normalizationContext: ['groups' => ['user:read']],
)]
class User
{
    /**
     * The 4 launch-required locales (spec: "обязательны на старте") — single source of
     * truth wherever a locale value needs validating (Phase 6i plan). Matches the
     * frontend's SUPPORTED_LOCALES (frontend/src/i18n/index.ts) but this list exists
     * independently since the two sides validate different things at different times.
     */
    public const SUPPORTED_LOCALES = ['en', 'ru', 'lv', 'es'];

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    #[Groups(['user:read'])]
    private string $id;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    #[Groups(['user:read'])]
    #[AllowPlaintext(reason: 'Server-visible for login/invites — always plaintext, see ADR 1.')]
    private string $email;

    /**
     * Plaintext, like email — not a secret, and needed server-side to appear in
     * GET /api/users (the counterpart picker) and in admin/platform-admin listings.
     * Empty string means "not set": every display site falls back to email in that
     * case (see frontend/src/userDisplay.ts) rather than treating this as required.
     */
    #[ORM\Column(type: 'string', length: 255)]
    #[Groups(['user:read'])]
    #[AllowPlaintext(reason: 'Same sensitivity as email — see docs/decisions/2026-08-25-plaintext-display-name.md.')]
    private string $displayName;

    /**
     * The tenant boundary (see private/cloud-service-plan.md, not tracked in git).
     * Deliberately no serialization group — GET /api/users is already scoped to the
     * requester's own company (ExcludeDeletedUsersExtension), so which company a listed
     * user belongs to is never information the API needs to expose; a compromised
     * frontend build learning company ids for free would be a real (if minor) leak this
     * avoids for no functional cost, since the frontend never needs this value.
     */
    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

    /**
     * The HKDF "auth" verifier — never the password, never the master-key.
     * Deliberately has no serialization group: must never be exposed via the API.
     */
    #[ORM\Column(type: 'string')]
    #[AllowPlaintext(reason: 'HKDF auth verifier, not the password or master-key — never serialized.')]
    private string $authHash;

    #[ORM\Column(type: 'text')]
    #[Groups(['user:read'])]
    #[AllowPlaintext(reason: 'A public key is meant to be public.')]
    private string $publicKey;

    /**
     * The user's private key, encrypted client-side with their master-key.
     * Deliberately has no serialization group: must never be exposed via the API.
     */
    #[ORM\Column(type: 'text')]
    private string $encryptedPrivateKey;

    /**
     * Null until the first password reset — see resetCredentials(). Compared against
     * each Anketa's per-side sealedKeyUpdatedAt (password-reset plan, part 2) to compute
     * whether a counterpart's copy of an anketa's key still matches this user's current
     * public key. Not user-facing, no serialization group needed.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publicKeyUpdatedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['user:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'boolean')]
    #[Groups(['user:read'])]
    private bool $isAdmin;

    /** Reversible, login-only gate (Phase 6g) — doesn't touch data, unlike account deletion. */
    #[ORM\Column(type: 'boolean')]
    #[Groups(['user:read'])]
    private bool $isBlocked = false;

    /**
     * Marks one of the fixed, publicly-documented demo accounts (see
     * bin/console app:reset-demo-data and private/demo-mode-plan.md, not
     * tracked in git). Two things key off this: ExcludeDeletedUsersExtension
     * also filters isDemo out of the public GET /api/users counterpart-picker
     * (a real prospect's typeahead shouldn't surface "Demo Employee"), and
     * AuthController::me() exposes it (added manually to that hand-built
     * response, same as every other /api/me field — this column deliberately
     * carries no serialization group, since demo accounts are filtered out of
     * the GET /api/users ApiResource entirely and there's nothing for the
     * group to expose there) so the frontend can show a persistent "you're
     * viewing the shared demo" banner instead of letting a visitor believe
     * they've created a private account. Never set via any API — only ever
     * true for rows the reset command itself manages.
     */
    #[ORM\Column(type: 'boolean')]
    private bool $isDemo = false;

    /**
     * The SaaS operator's own cross-company support/moderation role (Phase C of
     * private/cloud-service-plan.md, not tracked in git) — entirely separate from
     * `isAdmin`, which is scoped to this user's own company (see AdminController).
     * Deliberately **not** grantable through any HTTP endpoint a company admin can
     * reach — only `bin/console app:grant-platform-admin` (CLI) or an existing platform
     * admin via `PlatformAdminController` can set this, mirroring how `isAdmin` itself
     * is only ever granted via the CLI bootstrap or an existing (company) admin. No
     * serialization group — not needed by GET /api/users (the counterpart picker);
     * `AuthController::me()` exposes it manually, same as `isDemo`.
     */
    #[ORM\Column(type: 'boolean')]
    private bool $isPlatformAdmin = false;

    /**
     * Which language outbound emails to this user are sent in (Phase 6i) — deliberately
     * *not* what drives the frontend's displayed language (that's a client-only
     * localStorage preference, Phase 6h); this only answers "what language should an
     * email to this person be in." No serialization group — not needed by the frontend,
     * which never reads it back (see the Phase 6i plan for why that's a one-way flow).
     */
    #[ORM\Column(type: 'string', length: 5)]
    #[AllowPlaintext(reason: 'A locale code (e.g. "en"), not content.')]
    private string $locale = 'en';

    /**
     * Gates AnketaNotifier::notifyMeetingTomorrow()/notifyNotFilledOut() only — the
     * "new anketa scheduled" email (notifyAnketaCreated()) stays mandatory regardless,
     * per the account-settings plan. Defaults true so nobody's reminders silently stop
     * without an explicit opt-out.
     */
    #[ORM\Column(type: 'boolean')]
    private bool $meetingRemindersEnabled = true;

    /**
     * Null unless this account has been deleted (AuthController::deleteAccount()) — set
     * by delete() below. Not a real row deletion: Anketa.employee/manager and Goal.author
     * are non-nullable FKs to User, and "no cascade to the pair's anketas" rules out
     * cascading a real delete anyway, so this row stays and gets anonymized in place
     * instead. ExcludeDeletedUsersExtension filters these out of the public
     * GET /api/users listing (the counterpart-picker).
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct(
        string $email,
        string $authHash,
        string $publicKey,
        string $encryptedPrivateKey,
        Company $company,
        bool $isAdmin = false,
        string $locale = 'en',
        string $displayName = '',
    ) {
        $this->id = Uuid::v7()->toRfc4122();
        $this->email = $email;
        $this->authHash = $authHash;
        $this->publicKey = $publicKey;
        $this->encryptedPrivateKey = $encryptedPrivateKey;
        $this->company = $company;
        $this->createdAt = new \DateTimeImmutable();
        $this->isAdmin = $isAdmin;
        $this->locale = \in_array($locale, self::SUPPORTED_LOCALES, true) ? $locale : 'en';
        $this->displayName = $displayName;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): void
    {
        $this->displayName = $displayName;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getAuthHash(): string
    {
        return $this->authHash;
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function getEncryptedPrivateKey(): string
    {
        return $this->encryptedPrivateKey;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isAdmin(): bool
    {
        return $this->isAdmin;
    }

    public function isBlocked(): bool
    {
        return $this->isBlocked;
    }

    public function setAdmin(bool $isAdmin): void
    {
        $this->isAdmin = $isAdmin;
    }

    public function setBlocked(bool $isBlocked): void
    {
        $this->isBlocked = $isBlocked;
    }

    public function isPlatformAdmin(): bool
    {
        return $this->isPlatformAdmin;
    }

    public function setPlatformAdmin(bool $isPlatformAdmin): void
    {
        $this->isPlatformAdmin = $isPlatformAdmin;
    }

    public function isDemo(): bool
    {
        return $this->isDemo;
    }

    public function setDemo(bool $isDemo): void
    {
        $this->isDemo = $isDemo;
    }

    /**
     * Replaces the auth verifier and both key-related fields at once — the one
     * mutation path outside the constructor for these three, used only by
     * password reset (PasswordResetController). A fresh keypair means every
     * anketa sealed under the old public key becomes unreadable until
     * re-shared — see Anketa::resealKeyFor() and publicKeyUpdatedAt below,
     * which this method sets so that consequence can actually be detected.
     */
    public function resetCredentials(string $authHash, string $publicKey, string $encryptedPrivateKey): void
    {
        $this->authHash = $authHash;
        $this->publicKey = $publicKey;
        $this->encryptedPrivateKey = $encryptedPrivateKey;
        $this->publicKeyUpdatedAt = new \DateTimeImmutable();
    }

    public function getPublicKeyUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->publicKeyUpdatedAt;
    }

    /**
     * Used only by bin/console app:reset-demo-data to restore one of the
     * fixed, publicly-known demo accounts to its seeded credentials — e.g.
     * if a visitor used the in-app "change password" flow on it, which
     * would otherwise lock out every other visitor until the next reset.
     * Deliberately does *not* bump publicKeyUpdatedAt the way
     * resetCredentials() does: a scheduled demo reset isn't a real
     * password-reset event, and bumping it would spuriously mark the
     * counterpart's sealed anketa key as outdated on every reset.
     */
    public function resetDemoCredentials(string $authHash, string $publicKey, string $encryptedPrivateKey): void
    {
        $this->authHash = $authHash;
        $this->publicKey = $publicKey;
        $this->encryptedPrivateKey = $encryptedPrivateKey;
    }

    /**
     * The in-app "change password while still logged in" mutation (AuthController's
     * PUT /api/me/password) — unlike resetCredentials(), the underlying keypair (and
     * therefore publicKey) doesn't change: the caller already has their current master
     * key, so this is just a re-wrap of the same private key under a new one. No
     * publicKeyUpdatedAt bump, since there's no anketa re-sharing consequence here.
     */
    public function changePassword(string $authHash, string $encryptedPrivateKey): void
    {
        $this->authHash = $authHash;
        $this->encryptedPrivateKey = $encryptedPrivateKey;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    /** @throws \InvalidArgumentException if $locale isn't one of self::SUPPORTED_LOCALES — callers validate before calling this, this is a last-resort guard. */
    public function setLocale(string $locale): void
    {
        if (!\in_array($locale, self::SUPPORTED_LOCALES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported locale "%s".', $locale));
        }
        $this->locale = $locale;
    }

    public function wantsMeetingReminders(): bool
    {
        return $this->meetingRemindersEnabled;
    }

    public function setMeetingRemindersEnabled(bool $enabled): void
    {
        $this->meetingRemindersEnabled = $enabled;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    /**
     * Self-service account deletion (AuthController::deleteAccount()) — anonymizes this
     * row in place rather than removing it (see $deletedAt's docblock for why). Scrubs
     * every identifying/sensitive field: email (rewritten to a non-identifying,
     * collision-free placeholder using this account's own id), authHash (a fresh value
     * nothing will ever match), encryptedPrivateKey (only ever read by this account's own
     * login/`/api/me`, which is now permanently blocked). isBlocked/isAdmin are forced for
     * defense-in-depth. meetingRemindersEnabled is forced off so SendRemindersCommand
     * never emails the now-fake address — reusing that toggle rather than adding new
     * gating logic elsewhere.
     *
     * publicKey is deliberately left UNCHANGED: Anketa.svelte's handleArchive() seals a
     * next-anketa key to a counterpart's publicKey client-side *before* the server's own
     * isBlocked skip-check runs (that check only decides whether the server keeps the
     * result) — an empty/invalid publicKey would make that seal throw and crash a live
     * counterpart's archive flow. A stale-but-well-formed key is inert instead.
     */
    public function delete(): void
    {
        $this->email = sprintf('deleted-%s@deleted.invalid', $this->id);
        $this->displayName = '';
        $this->authHash = bin2hex(random_bytes(32));
        $this->encryptedPrivateKey = '';
        $this->isAdmin = false;
        $this->isPlatformAdmin = false;
        $this->isBlocked = true;
        $this->meetingRemindersEnabled = false;
        $this->deletedAt = new \DateTimeImmutable();
    }
}

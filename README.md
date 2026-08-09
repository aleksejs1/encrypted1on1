# encrypted1on1

A self-hosted, end-to-end encrypted platform for running 1:1 meetings between managers and employees.

## Status

Early stage. Implementation has just started: a minimal skeleton (backend boots, frontend boots, they talk to each other) exists, but no real functionality — auth, encryption, the 1:1 flow itself — has landed yet.

## Core idea

- **Self-hosted.** Your company runs it, your data stays on your own infrastructure.
- **End-to-end encrypted.** 1:1 content is encrypted client-side; the server only ever stores ciphertext derived from each user's password. Not even whoever operates the server can read it.
- **Open source.** Licensed under AGPLv3, so the privacy claims above can actually be verified by reading the code, not just taken on faith.

## Quick start (dev)

```
make up          # starts the backend (FrankenPHP) and Mailpit
cd frontend
npm install
npm run dev      # frontend dev server, proxies API calls to the backend
```

`make down` stops the backend/Mailpit containers.

## License

AGPLv3 — see [LICENSE](LICENSE).

## Contributing

Not open for contributions yet — the project is still being scoped. This section will be updated once there's a codebase to contribute to.

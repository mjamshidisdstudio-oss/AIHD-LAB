# CI/CD Setup — GitHub Actions

## Required GitHub Secrets

Add these **Repository Secrets** in `Settings → Secrets and variables → Actions`:

| Secret | Value | Get via |
|---|---|---|
| `SSH_PRIVATE_KEY` | Deploy SSH private key | `cat /root/.ssh/github-actions` on server |
| `SSH_KNOWN_HOSTS` | Host key fingerprint | `ssh-keyscan -H 91.107.186.95` |
| `SSH_HOST` | `91.107.186.95` | — |
| `SSH_USER` | `root` | — |

### 1. Generate deploy key on the server (already done)

```bash
ssh root@91.107.186.95
ssh-keygen -t ed25519 -f /root/.ssh/github-actions -N "" -C "github-actions@aihd-lab"
cat /root/.ssh/github-actions.pub >> /root/.ssh/authorized_keys
```

### 2. Copy the private key into GitHub Secrets

```bash
ssh root@91.107.186.95 "cat /root/.ssh/github-actions"
```

Copy the **entire output** (from `-----BEGIN OPENSSH PRIVATE KEY-----` to `-----END OPENSSH PRIVATE KEY-----`) into the `SSH_PRIVATE_KEY` secret.

### 3. Copy the host key into GitHub Secrets

```bash
ssh-keyscan -H 91.107.186.95
```

Copy the output into `SSH_KNOWN_HOSTS`.

## Pipeline behavior

```
push to main → PHPUnit (PHP 8.2/8.3/8.4) → if all pass → deploy to showroom-germany
```

### What the deploy does

1. `git fetch` + `git reset --hard origin/main` on `/opt/aihd-lab`
2. Build Docker images (`app` + `marketplace`)
3. Restart containers
4. Run `php artisan migrate --force`
5. Fix storage permissions
6. Verify `https://api.revivoto.ai` and `https://app.revivoto.ai` respond `200`

## Manual deploy

```bash
ssh root@91.107.186.95 "cd /opt/aihd-lab && bash infra/scripts/deploy-aihd-lab.sh"
```
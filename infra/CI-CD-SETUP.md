# CI/CD Setup — GitHub Actions

## Required GitHub Secrets

Add these **Repository Secrets** in `Settings → Secrets and variables → Actions`:

| Secret | Value | Get via |
|---|---|---|
| `SSH_PRIVATE_KEY_B64` | Base64 deploy key | `base64 -w 0 /root/.ssh/github-actions` on server |
| `SSH_HOST` | `91.107.186.95` | — |
| `SSH_USER` | `root` | — |

### 1. Generate deploy key on the server (already done)

```bash
ssh root@91.107.186.95
ssh-keygen -t ed25519 -f /root/.ssh/github-actions -N "" -C "github-actions@aihd-lab"
cat /root/.ssh/github-actions.pub >> /root/.ssh/authorized_keys
```

### 2. Store the deploy key in GitHub Secrets (base64)

```bash
ssh root@91.107.186.95 "base64 -w 0 /root/.ssh/github-actions" | gh secret set SSH_PRIVATE_KEY_B64 -R owner/AIHD-LAB
gh secret set SSH_HOST -R owner/AIHD-LAB -b "91.107.186.95"
gh secret set SSH_USER -R owner/AIHD-LAB -b "root"
```

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
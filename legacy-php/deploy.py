#!/usr/bin/env python3
"""Ginger Sync — FTP/TLS deployment script.

Usage:
    python3 deploy.py                 # deploy all files (except skipped)
    python3 deploy.py pages/foo.php   # deploy a single file

Credentials are loaded from /Users/julien.borer/Documents/bqbqb/.env.
If your FTP account is NOT chrooted to /ginger-sync/, set
    FTP_REMOTE_PREFIX=ginger-sync
in that .env so uploads land under the right site directory.
"""

import ftplib, os, sys

ENV_FILE = '/Users/julien.borer/Documents/bqbqb/.env'
BASE_DIR = os.path.dirname(os.path.abspath(__file__))

SKIP = {'.env', '.git', '.DS_Store', 'node_modules', 'deploy.py',
        '.claude', 'CLAUDE.md', 'CHANGELOG.md', '.env.example',
        'schema.sql', 'README.md'}


def load_env():
    env = {}
    with open(ENV_FILE) as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith('#'):
                continue
            if '=' in line:
                k, v = line.split('=', 1)
                env[k.strip()] = v.strip()
    return env


def connect(env):
    ftp = ftplib.FTP_TLS()
    ftp.connect(env['FTP_HOST'], int(env.get('FTP_PORT', '21')))
    ftp.login(env['FTP_USER'], env['FTP_PASS'])
    ftp.prot_p()
    return ftp


def remote_path(rel_path, prefix):
    return f'{prefix}/{rel_path}' if prefix else rel_path


def ensure_remote_dir(ftp, rel_path):
    remote_dir = os.path.dirname(rel_path)
    if remote_dir:
        parts = remote_dir.split('/')
        for i in range(len(parts)):
            d = '/'.join(parts[:i + 1])
            try:
                ftp.mkd(d)
            except Exception:
                pass


def should_skip(path):
    parts = path.split('/')
    for s in SKIP:
        if s in parts or path.endswith(s):
            return True
    return False


def deploy_single(ftp, rel_path, prefix):
    local = os.path.join(BASE_DIR, rel_path)
    if not os.path.isfile(local):
        print(f'ERROR: {rel_path} not found')
        sys.exit(1)
    remote = remote_path(rel_path, prefix)
    ensure_remote_dir(ftp, remote)
    with open(local, 'rb') as f:
        ftp.storbinary('STOR ' + remote, f)
    print(f'  {remote}')


def deploy_all(ftp, prefix):
    count = errors = 0
    for root, dirs, files in os.walk(BASE_DIR):
        dirs[:] = [d for d in dirs if not d.startswith('.') and d not in SKIP]
        for fname in files:
            if fname.endswith('.zip'):
                continue
            if fname.startswith('.') and fname != '.htaccess':
                continue
            local = os.path.join(root, fname)
            rel = os.path.relpath(local, BASE_DIR)
            if should_skip(rel):
                continue
            remote = remote_path(rel, prefix)
            ensure_remote_dir(ftp, remote)
            try:
                with open(local, 'rb') as f:
                    ftp.storbinary('STOR ' + remote, f)
                count += 1
                print(f'  {remote}')
            except Exception as e:
                errors += 1
                print(f'  FAIL {remote}: {e}')
    print(f'\nDeployed {count} files ({errors} errors)')


if __name__ == '__main__':
    env = load_env()
    prefix = env.get('FTP_REMOTE_PREFIX', '').strip('/')
    target = sys.argv[1] if len(sys.argv) > 1 else None

    ftp = connect(env)
    if target:
        print(f'Deploying: {target}')
        deploy_single(ftp, target, prefix)
    else:
        print('Ginger Sync Deploy — All files')
        print('═' * 32)
        if prefix:
            print(f'Remote prefix: /{prefix}/')
        deploy_all(ftp, prefix)
    ftp.quit()
    print('Done.')

#!/usr/bin/env python3
"""Read-only checks for the deployed single-container NGINX/PHP security boundary.

Run after building an image: python3 tests/runtime_security_test.py --container NAME
This is a dated security baseline, not a replacement for a current CVE database.
"""
import argparse
import json
import re
import subprocess


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--container', required=True)
    args = parser.parse_args()
    passed = 0

    def command(*cmd):
        result = subprocess.run(['docker', 'exec', args.container, *cmd],
                                capture_output=True, text=True, check=True, timeout=30)
        return result.stdout + result.stderr

    def check(condition, label):
        nonlocal passed
        if not condition:
            raise RuntimeError(label)
        passed += 1
        print('PASS', label)

    runtime = json.loads(command('php', '-r', '''
        echo json_encode([
            'version' => PHP_VERSION,
            'branch' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'upload_progress' => (bool) ini_get('session.upload_progress.enabled')
        ]);
    '''))
    floors = {'8.2': '8.2.35', '8.3': '8.3.36', '8.4': '8.4.27', '8.5': '8.5.12'}
    floor = floors.get(runtime['branch'])
    check(floor is not None and command('php', '-r',
          f'echo version_compare(PHP_VERSION, "{floor}", ">=") ? "yes" : "no";') == 'yes',
          'PHP includes the October 2026 critical fixes')
    check(not runtime['upload_progress'], 'session upload progress is disabled')

    config = command('php-fpm', '-tt')
    for setting, expected in [('listen', '127.0.0.1:9000'),
                              ('listen.allowed_clients', '127.0.0.1'),
                              ('security.limit_extensions', '.php')]:
        values = re.findall(r'NOTICE:\s+' + re.escape(setting) + r' = (.*)', config)
        check(values == [expected], 'effective FPM ' + setting)

    # Connect to the running listener, not just the configuration on disk.
    # Every assigned container IP must refuse FastCGI; loopback must accept it.
    network = json.loads(subprocess.check_output(
        ['docker', 'inspect', '--format', '{{json .NetworkSettings.Networks}}', args.container],
        text=True, timeout=30))
    addresses = [v[key] for v in network.values()
                 for key in ['IPAddress', 'GlobalIPv6Address'] if v.get(key)]
    check(bool(addresses), 'container has a non-loopback address to probe')
    probe = '''
        $s = @fsockopen($argv[1], 9000, $errno, $error, 1);
        echo $s ? 'open' : 'closed';
        if ($s) fclose($s);
    '''
    check(command('php', '-r', probe, '127.0.0.1') == 'open', 'FPM accepts loopback')
    for addr in addresses:
        target = '[' + addr + ']' if ':' in addr else addr
        check(command('php', '-r', probe, target) == 'closed',
              'FPM refuses container interface ' + addr)

    packages = {}
    for block in command('cat', '/lib/apk/db/installed').split('\n\n'):
        fields = dict(line.split(':', 1) for line in block.splitlines() if ':' in line)
        if 'P' in fields:
            packages[fields['P']] = fields['V']
    version = packages['libexpat']
    check(command('apk', 'version', '-t', version, '2.10.0-r0').strip() in ['=', '>'],
          'libexpat includes the November 2026 security fixes')
    check(command('apk', 'version', '-t', packages['brotli-libs'], '1.2.1-r0')
          .strip() in ['=', '>'],
          'Brotli includes CVE-2026-111497 fix')
    check(command('curl', '-fsS', 'http://127.0.0.1/health.php').strip() == 'OK',
          'NGINX can serve PHP through the restricted listener')
    print(f'{passed} runtime security checks passed; PHP {runtime["version"]}, libexpat {version}')


if __name__ == '__main__':
    main()

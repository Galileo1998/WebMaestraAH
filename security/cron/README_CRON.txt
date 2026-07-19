CRON RECOMENDADO EN CPANEL

Sustituya USUARIO y la ruta de PHP según su hosting.

Cada hora, escaneo rápido:
0 * * * * /usr/local/bin/php /home/USUARIO/public_html/security/cli_scan.php --mode=quick >/dev/null 2>&1

Todos los días a las 2:15 a. m., escaneo completo:
15 2 * * * /usr/local/bin/php /home/USUARIO/public_html/security/cli_scan.php --mode=full >/dev/null 2>&1

Domingos a las 3:00 a. m., respaldo privado del código:
0 3 * * 0 /usr/local/bin/php /home/USUARIO/public_html/security/cli_backup.php >/dev/null 2>&1

Push programado opcional:
30 3 * * 0 /usr/local/bin/php /home/USUARIO/public_html/security/cli_git_push.php --message="Respaldo semanal automático" >/dev/null 2>&1

Alternativa web protegida:
https://accionhonduras.org/security/cron_web.php?task=quick&token=00c56aacd539414260d250b68f1dcfce45f68d2a7d43842f3c1f308e61419042

Se recomienda CLI porque el token no queda registrado en URLs.

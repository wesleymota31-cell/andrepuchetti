# André Puchetti Agenda

Sistema de agenda e área do cliente do Salão André Puchetti.

## Estrutura

- `agenda-public_html/`: aplicação PHP publicada no domínio da agenda.
- `agenda-public_html/admin/`: painel administrativo e agenda visual.
- `agenda-public_html/includes/`: autenticação, e-mail, SEO e helpers reutilizáveis.

## Configuração

O arquivo `agenda-public_html/config.php` lê as credenciais de um arquivo externo `agenda_config.php` fora do `public_html` ou por variáveis de ambiente:

- `AGENDA_DB_HOST`
- `AGENDA_DB_NAME`
- `AGENDA_DB_USER`
- `AGENDA_DB_PASS`

As credenciais SMTP também devem ficar fora do repositório.

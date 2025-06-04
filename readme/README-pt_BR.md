# Two-Factor

Habilite a Autenticação de Dois Fatores (2FA) usando senhas únicas baseadas em tempo (TOTP), Universal 2nd Factor (U2F), email e códigos de verificação de backup.

## Descrição

Use a seção "Opções de Dois Fatores" em "Usuários" → "Seu Perfil" para habilitar e configurar um ou múltiplos provedores de autenticação de dois fatores para sua conta:

- Códigos por email
- Senhas únicas baseadas em tempo (TOTP)
- FIDO Universal 2nd Factor (U2F)
- Códigos de backup
- Método Dummy (apenas para fins de teste)

Para mais informações sobre o histórico, veja [este post](https://georgestephanis.wordpress.com/2013/08/14/two-cents-on-two-factor/).

## Actions & Filters

Aqui está uma lista de hooks de ação e filtro fornecidos pelo plugin:

- `two_factor_providers` filtro sobrescreve os provedores de dois fatores disponíveis, como email e senhas únicas baseadas em tempo. Os valores do array são nomes de classes PHP dos provedores de dois fatores.
- `two_factor_providers_for_user` filtro sobrescreve os provedores de dois fatores disponíveis para um usuário específico. Os valores do array são instâncias das classes de provedores e o objeto `WP_User` está disponível como segundo argumento.
- `two_factor_enabled_providers_for_user` filtro sobrescreve a lista de provedores de dois fatores habilitados para um usuário. O primeiro argumento é um array de nomes de classes de provedores habilitados como valores, o segundo argumento é o ID do usuário.
- `two_factor_user_authenticated` ação que recebe o objeto `WP_User` logado como primeiro argumento para determinar o usuário logado logo após o fluxo de autenticação.
- `two_factor_email_token_ttl` filtro sobrescreve o intervalo de tempo em segundos que um token de email é considerado após a geração. Aceita o tempo em segundos como primeiro argumento e o ID do objeto `WP_User` sendo autenticado.
- `two_factor_email_token_length` filtro sobrescreve a contagem padrão de 8 caracteres para tokens de email.
- `two_factor_backup_code_length` filtro sobrescreve a contagem padrão de 8 caracteres para códigos de backup. Fornece o `WP_User` do usuário associado como segundo argumento.

## Perguntas Frequentes

### Como posso enviar feedback ou obter ajuda com um bug?

O melhor lugar para reportar bugs, sugestões de recursos ou qualquer outro feedback (não relacionado à segurança) é na [página de issues do Two Factor no GitHub](https://github.com/WordPress/two-factor/issues). Antes de enviar um novo issue, por favor, pesquise os issues existentes para verificar se alguém já reportou o mesmo feedback.

### Onde posso reportar bugs de segurança?

Os contribuidores do plugin e a comunidade WordPress levam bugs de segurança a sério. Agradecemos seus esforços em divulgar suas descobertas de forma responsável e faremos todo o possível para reconhecer suas contribuições.

Para reportar um problema de segurança, por favor, visite o programa [WordPress HackerOne](https://hackerone.com/wordpress).

## Screenshots

1. Opções de dois fatores no Perfil do Usuário.
2. Seção de Chaves de Segurança U2F no Perfil do Usuário.
3. Autenticação por Código de Email durante o Login do WordPress.

## Changelog

Veja o [histórico de lançamentos](https://github.com/wordpress/two-factor/releases). 
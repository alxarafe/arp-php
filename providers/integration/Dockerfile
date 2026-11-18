FROM arp-cds-read-003-php-test:local
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev unixodbc-dev \
    && docker-php-ext-install pdo_pgsql
RUN curl -fsSL https://packages.microsoft.com/config/debian/12/packages-microsoft-prod.deb -o /tmp/ms.deb \
    && dpkg -i /tmp/ms.deb && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18 \
    && pecl install pdo_sqlsrv-5.13.3 && docker-php-ext-enable pdo_sqlsrv
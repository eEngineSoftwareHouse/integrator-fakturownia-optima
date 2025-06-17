FROM namoshek/php-mssql:8.3-cli     
RUN docker-php-ext-install pdo_mysql

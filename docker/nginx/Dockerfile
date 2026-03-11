FROM ubuntu:bionic

ARG DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
apt-utils curl gnupg2 ca-certificates lsb-release \
&& echo "deb http://nginx.org/packages/ubuntu $(lsb_release -cs) nginx" > /etc/apt/sources.list.d/nginx.list \
&& curl -fsSL https://nginx.org/keys/nginx_signing.key | apt-key add - \
&& apt-get update \
&& apt-get install -y nginx \
&& mkdir -p /etc/nginx/sites-enabled \
&& chown www-data:www-data /etc/nginx/sites-enabled -R

COPY config/nginx.conf /etc/nginx/nginx.conf
COPY config/sites-enabled /etc/nginx/sites-enabled

WORKDIR /etc/nginx

CMD ["nginx", "-g", "daemon off;"]
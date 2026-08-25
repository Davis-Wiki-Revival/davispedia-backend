FROM node:20 AS frontend-builder
RUN git clone https://github.com/Davis-Wiki-Revival/davispedia-frontend.git /frontend
WORKDIR /frontend
RUN npm install && npm run build

FROM mediawiki:1.46

RUN mkdir -p /var/www/html/extensions/DavispediaFrontend
COPY --from=frontend-builder /frontend/extension.json /var/www/html/extensions/DavispediaFrontend/
COPY --from=frontend-builder /frontend/dist /var/www/html/extensions/DavispediaFrontend/dist/

RUN chown -R www-data:www-data /var/www/html/extensions/DavispediaFrontend
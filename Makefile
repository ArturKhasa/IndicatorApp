connect_app:
	docker exec -it indicatorapp bash

connect_nginx:
	docker exec -it indicatorapp-nginx bash

down:
	docker compose -f docker-compose.yml down

up:
	docker compose -f docker-compose.yml up -d

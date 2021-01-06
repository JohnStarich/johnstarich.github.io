---
tags: teach
media: [/home-cloud/ingress-architecture.png]
---

# Setting up an ingress

Web services running on the same network can be difficult to set up correctly, especially if you want all of them to work on an external network. In this tutorial, I will guide you through setting up a Docker Swarm to route traffic to the appropriate containers, automatically.

There are three parts to get your cloud ready for web services: a load balancer to spread connections to available servers, an ingress router to direct traffic to containers, and DNS to point local traffic to the right IP addresses. See the above diagram for a quick layout.


We'll set up a couple things to bootstrap your network of web services. I assume you already have a Docker Swarm ([Docker][setup docker] & [Swarm][setup swarm]) and shared volumes ([tutorial][shared volumes]) set up on at least one node. I also highly recommend another server as a load balancer for higher availability -- a Raspberry Pi is fine -- but you can skip it if you're strapped for cash.

In my setup, I'm using Docker Swarm with two nodes: server A `10.1.0.2` and server B `10.1.0.4`. Additionally, I'm using a third server `10.1.0.3` as a load balancer. Throughout this tutorial, you'll need to replace these IPs with your own.

I have reference material [on GitHub][github] and if you have any questions in this tutorial, please [open an issue][new issues].

[setup docker]: https://docs.docker.com/engine/swarm/swarm-tutorial/
[setup swarm]: https://docs.docker.com/engine/swarm/swarm-tutorial/create-swarm/
[github]: https://github.com/JohnStarich/home-container-cloud/tree/master/ingress

## “Layer 4” load balancer

To use multiple nodes in a Swarm, you may need high availability. In other words, if you have servers A and B, but B is suddenly unplugged, then your cloud should keep working uninterrupted.

One way to achieve basic availability is to set up a dumb [“layer 4”][L4] load balancer to direct incoming web traffic to multiple Swarm nodes. I grabbed a spare server and installed nginx with a simple configuration. Nginx automatically detects failed connections and routes traffic away from failing nodes. Ideally, this server's only job is to load balance connections without interruption.

Let's get started by [installing nginx][install nginx]!

Next, copy this config to nginx's config directory, usually `/etc/nginx/conf.d/`. Be sure to change the servers to your own IP addresses. The nginx `upstreams` defined below are for HTTP connections on port 8080, HTTPS on 4443, and DNS on 53.

[L4]: https://en.wikipedia.org/wiki/OSI_model#Layer_4:_Transport_Layer
[install nginx]: https://nginx.org/en/docs/install.html
 
```config
stream {
    upstream https_backend {
        least_conn;
        server 10.1.0.2:443;
        server 10.1.0.4:443;
    }

    server {
        listen        4443;
        proxy_pass    https_backend;
        proxy_connect_timeout 30s;
        proxy_protocol on;
    }

    upstream http_backend {
        least_conn;
        server 10.1.0.2:80;
        server 10.1.0.4:80;
    }

    server {
        listen        8080;
        proxy_pass    http_backend;
        proxy_connect_timeout 30s;
        proxy_protocol on;
    }

    upstream dns_backend {
        least_conn;
        server 10.1.0.2:53;
        server 10.1.0.4:53;
    }

    server {
        listen        53 udp;
        proxy_pass    dns_backend;
        proxy_timeout 30s;
        proxy_connect_timeout 30s;
    }
}
```

Configure your home router to port forward 80 to 8080, 443:4443 and 53:53 to the spare server's IP. This will route traffic from the internet to your load balancer (and later to your containers).

Finally, reload nginx with `sudo nginx -s reload`.

This ensures basic stability in the event of node failures by balancing traffic between healthy nodes.

If you've already used nginx as an HTTP proxy before, then this config may look familiar. However, this is way simpler than an HTTP proxy. It will forward any incoming packets on these ports to a server, HTTP or otherwise. Dumb forwarding is very useful for other protocols, like DNS, as we'll see later.

#### Note

You may have noticed that the HTTP and HTTPS servers are configured to use the proxy protocol. This preserves the original IP addresses of incoming requests when they reach the ingress router. If you use an ingress router that is *not* configured to use the proxy protocol (different from the one in this tutorial), then you should disable it instead.


## Ingress router

Now that all incoming traffic is directed to each Swarm node, we need to forward HTTP requests to the appropriate containers. We'll create an ingress router with [Traefik][] to direct those requests.

[Traefik]: https://traefik.io

First, you'll need a Traefik config. Copy the below to `traefik.toml`, remember to replace the email, domain name, and IP addresses with your own:

```config
logLevel = "INFO"
defaultEntryPoints = ["https","http"]

[entryPoints]
  [entryPoints.http]
  address = ":80"
    [entryPoints.http.redirect]
    entryPoint = "https"
  [entryPoints.https]
  address = ":443"
  [entryPoints.https.tls]
  # Enable proxy protocol support (properly detect IP addresses)
  [entryPoints.http.proxyProtocol]
    # List of trusted IPs = [local, load balancer IP]
    trustedIPs = ["127.0.0.1/32", "10.1.0.3"]
    # Insecure mode FOR TESTING ENVIRONMENT ONLY
    # insecure = true

[api]
dashboard = true

[ping]

[retry]

[docker]
endpoint = "unix:///var/run/docker.sock"
domain = "yourdomain.com"
# Auto-detect and react to changes
watch = true
# Require services to explicitly opt-in to Traefik
exposedByDefault = false
swarmMode = true
network = "web"
# Add an ingress tag constraint to support multiple, separate Traefik services deployed in Docker.
# Any service using this ingress Traefik service must have this tag.
constraints = ["tag==ingress"]

# Let's Encrypt config
[acme]
email = "YourEmailAddress@gmail.com"
# Store Let's Encrypt certs on the shared volume
storage = "/data/acme.json"
entryPoint = "https"
onHostRule = true
acmeLogging = true

# Let's Encrypt staging server (enable to test it out and avoid rate limiting)
#caServer = "https://acme-staging-v02.api.letsencrypt.org/directory"

# Use the Let's Encrypt HTTP challenge type.
[acme.httpChallenge]
entryPoint = "http"
```

(For testing purposes, uncomment the `caServer` line.)

Next, create a shared volume called `proxy_traefik` and an overlay network named `web`. If you need to set up shared volumes, you can [follow my tutorial][shared volumes]. I'm using the `convoy` volume driver.

```bash
docker volume create --driver=convoy proxy_traefik
docker network create --driver=overlay web
```

Copy the `traefik.toml` into your shared volume. Here's one way you could do that:

```bash
docker run --rm \
    -v "$PWD":/conf \
    -v proxy_traefik:/data \
    --volume-driver=convoy \
    alpine sh -c 'cp /conf/traefik.toml /data/'
```

Next, copy the below Docker config into `docker-compose.yml`:

```yaml
version: "3.4"

networks:
  web:
    external: true

volumes:
  traefik:
    driver: convoy

services:
  traefik:
    image: traefik:1.7
    command: ["--configfile=/data/traefik.toml"]
    ports:
    - "80:80"
    - "443:443"
    # Optional: Traefik dashboard port
    # This is not exposed on the load balancer, but is nice to have internally
    #- "8080:8080"
    networks:
    - web
    volumes:
    - /var/run/docker.sock:/var/run/docker.sock
    - traefik:/data
    deploy:
      mode: replicated
      replicas: 3
      update_config:
        order: start-first
      resources:
        reservations:
          cpus: '0.1'
          memory: 10M
      placement:
        constraints:
        - node.role == manager
    healthcheck:
      test: ["CMD", "/traefik", "healthcheck", "--configfile=/data/traefik.toml"]
```

Finally, deploy it with `docker stack deploy -c docker-compose.yml proxy`.

You now have a working ingress router! Next up, we'll make it possible for other computers on your network to access web services running inside the Swarm.

#### Note
If you are using an L4 load balancer and you want *local* network IP addresses to be correct, change the stack to use these settings:

```yaml
    # 'mode: host' is important to preserve the original IP addresses of *all* incoming requests.
    # Also change your "deploy:" mode to "global" so each manager node gets a host binding.
    ports:
    - target: 80
      published: 80
      protocol: tcp
      mode: host
    - target: 443
      published: 443
      protocol: tcp
      mode: host
    deploy:
      mode: global
      # no replicas
      update_config:
        order: stop-first
```

Don't forget to switch to `mode: global` under the deploy settings, there can only be one container bound to a host port on each node.

Without the above port changes, the local network IPs will always be the Swarm gateway IP. For more information, see [this Docker issue](https://github.com/moby/moby/issues/25526).

#### Warning: For users with more than one Docker node

If you're using more than one Docker node, I highly recommend using a key-value database to store your Traefik config and auto-generated certificates.

Using a shared database makes it possible for Traefik to correctly obtain Let's Encrypt certificates on any node, even if a different node requested new certificates. For more information, see the Traefik docs to [set up high-availability][HA].

[HA]: https://docs.traefik.io/user-guide/cluster/


## DNS

The last step to make your cloud usable at home is to add DNS. DNS makes it easy to access your services running in Docker from outside the private Swarm network.

Add this to the end of the proxy's `docker-compose.yml`:

```yaml
  dns:
    image: johnstarich/dns:0.1
    ports:
    - "53:53/tcp"
    - "53:53/udp"
    deploy:
      # I like to ensure DNS is running everywhere. It helps when rebalancing containers onto new hosts.
      mode: global
      update_config:
        order: start-first
      resources:
        reservations:
          cpus: '0.1'
          memory: 10M
    # CloudFlare DNS
    dns:
    - 1.0.0.1
    - 1.1.1.1
    extra_hosts:
    - "yourdomain.com:10.1.0.2"
    - "yourdomain.com:10.1.0.4"
    - "www.yourdomain.com:10.1.0.2"
    - "www.yourdomain.com:10.1.0.4"
 ```

And re-deploy the yaml file: `docker stack deploy -c docker-compose.yml proxy`


## Deploying a "hello world" service

Lookin' good so far. Now we should spin up a service to verify everything is working the way we want. Copy the below into `hello.yml`:

```yaml
version: "3"

networks:
  web:
    external: true

services:
  hello:
    image: tutum/hello-world:latest
    networks:
    - default
    - web
    deploy:
      replicas: 3
      labels:
      - "traefik.tags=ingress"
      - "traefik.enable=true"
      - "traefik.port=80"
      - "traefik.frontend.rule=Host:www.yourdomain.com"
```

Deploy it with `docker stack deploy -c hello.yml hello`.

After about 20 seconds (Traefik's Docker polling period), verify it's all working with `curl -H "Host: www.yourdomain.com" --insecure https://10.1.0.2/`. (Keep in mind, the SSL cert will be invalid if Traefik has errors retrieving certificates. Check the Traefik logs for more information.)

If your laptop's DNS is pointing to the load balancer, then you can also visit `https://www.yourdomain.com` in your browser. I've configured my home router's DNS to point to `10.1.0.3` so everything on the network can resolve `yourdomain.com`.

## Summary

You now have a fully-functional, highly available ingress. Now all you need are real services to run on it!

## Questions?

If you have *any* questions please [open an issue here][new issues].

[new issues]: https://github.com/JohnStarich/home-container-cloud/issues

[shared volumes]: {% link _posts/2019-03-24-Shared-volumes-in-Docker.md %}

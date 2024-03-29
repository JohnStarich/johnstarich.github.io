---
tags: teach
hidden: true
---

# Shared volumes in Docker

Scaling an application is hard, especially when you need to manage its important data yourself. One way to make this easier is by sharing the files across multiple servers -- no matter where it runs, the data will always be available.

An important step in making highly available applications is setting up highly available data. With Docker, we will set up shared files with a volume plugin called [Convoy][convoy].

In this tutorial I assume you already have 2 or more nodes in a Docker Swarm and an NFS v3 server set up. If you haven't set up a Docker Swarm yet, get at least 2 nodes ready by following these tutorials for [Docker setup][setup docker] and [Swarm setup][setup swarm].


If you don't want to use NFS v3, that's okay --- Convoy supports multiple storage back-ends --- but you'll definitely need to use network attached storage. (Convoy's `devicemapper` back-end won't work here.) Also, I recommend avoiding Samba shares: Samba file permissions only support a single user ID, which wreaks havoc in containers that don't use the same ID.

This tutorial's instructions are based on Ubuntu 18.10, but they _should_ work on most Docker installations.

[convoy]: http://github.com/rancher/convoy
[setup docker]: https://docs.docker.com/engine/swarm/swarm-tutorial/
[setup swarm]: https://docs.docker.com/engine/swarm/swarm-tutorial/create-swarm/

## Configure NFS

First thing to do is configure NFS for use with Convoy. Convoy supports several [storage back-ends][convoy back-ends]. For this tutorial, we will use NFS v3 since they are easy to set up together.

[convoy back-ends]: https://github.com/rancher/convoy#start-convoy-daemon

Make sure your NFS v3 server is up and running. Create a new user and give it access to a new share (mine is `/server_volumes`). Convoy will connect as this user to create Docker volumes.

Next, make sure your Docker Swarm nodes are allowed to connect to NFS and have read/write permissions. The permissions should not be “squashed” at all. (In this tutorial I'm also **_not_** adding additional security with Kerberos.)

On _each_ Docker node, run the following commands:

```bash
sudo apt-get update
sudo apt-get install -y nfs-common
```

and add this NFS mount to the bottom of `/etc/fstab`. This entry ensures NFS mounts at boot. Don't forget to add your NFS connection info!

```config
# Replace 10.1.0.5 with your NFS server's IP or hostname
# Replace /server_volumes with your NFS server's volume mount path
# auto: attempts to mount at startup
# bg: auto-retries mounting even if it fails the first time
# actimeo: caches (and buffers) file metadata changes to increase performance
10.1.0.5:/server_volumes /mnt/nfs/volumes nfs auto,bg,nofail,noatime,intr,tcp,actimeo=1800 0 0
```

Reboot each server and check the output of `mount` to verify NFS mounted automatically.

## Install Convoy

To create Docker volumes on the NFS share, we will need to set up Convoy. You must repeat this section for _each_ Docker Swarm node.

Run the following to install Convoy:

```bash
wget https://github.com/rancher/convoy/releases/download/v0.5.2/convoy.tar.gz
tar xvzf convoy.tar.gz
sudo cp convoy/convoy convoy/convoy-pdata_tools /usr/local/bin/
sudo mkdir -p /etc/docker/plugins/
sudo bash -c 'echo "unix:///var/run/convoy/convoy.sock" > /etc/docker/plugins/convoy.spec'
```

Next, add this to `/etc/systemd/system/convoy.service`:

```config
[Unit]
Description=Convoy Daemon

[Service]
ExecStart=/usr/local/bin/convoy daemon --drivers vfs --driver-opts vfs.path=/mnt/nfs/volumes --cmd-timeout=10m
# Add environment variables for Convoy here
#Environment="SOME_VAR=VALUE"

[Install]
WantedBy=multi-user.target
```

Run `sudo systemctl daemon-reload` to pick up the new service, then start it with `sudo systemctl start convoy`. Check Convoy's logs with `journalctl -f -u convoy` to make sure it started successfully.

#### Extra Convoy features
If you want to use Convoy's other features, you can also add environment variables to the systemd config (as indicated). If there's enough interest, I could make another tutorial for creating backups of Convoy volumes on your own S3 server.

### Verify Convoy installation

Double-check that Convoy is set up correctly with NFS. Create a shared volume and place a file on it:

```bash
docker volume create --driver=convoy my_volume
docker run --rm -v my_volume:/data alpine:3.8 sh -c 'echo "hi there!" > /data/hello'
```

After setting up Convoy on two or more nodes, run the following command on two different nodes. Both times it should print "hi there!":

```bash
docker run --rm -v my_volume:/data alpine:3.8 cat /data/hello
# hi there!
```

## Summary

Shared volumes are essential to ensuring your data is always available. Now you can mount the same data into a container on any node in your Swarm!

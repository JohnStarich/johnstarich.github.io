---
layout: experience
title: IKS Logs & Metrics Forwarder
company: IBM
start_date: June 2017
date:   2017-06-20 00:00:00 -0600
category: experiences
---
On [IBM Cloud's Kubernetes Service][iks], you can create a cluster and tick a box to enable logging. The magic behind that little box is enabled with a logs and metrics forwarder that my team builds and maintains.

The metrics and log-forwarding service captures various stats and logs generated in a user's Kubernetes cluster, then forwards them to the IBM Log Analysis and Monitoring services by default. We picked up the service in its proof-of-concept stage and completely revamped it to fit newer features and our rigorous quality standards to run in production.


One of the first things I did to help bring the service up to speed in production was to create a new continuous integration pipeline for the service. The pipeline automatically ran tests, style checks, and linters to catch bugs as early as possible.

Later on, I completely rewrote how we configured the forwarding agent to support dynamic config generation from a "manager" microservice. This dynamic config generation paved the way for easier feature additions and better user experiences down the road. Some of the major new features I introduced include the ability to send logs via syslog, automatically parse JSON container logs for structured log support, and send logs to multiple endpoints. I also wrote a [blog post][blog] to guide users through sending their container logs to their own syslog server.

[iks]: https://www.ibm.com/cloud/container-service
[blog]: https://www.ibm.com/blogs/bluemix/2017/11/kubernetes-log-forwarding-syslog/

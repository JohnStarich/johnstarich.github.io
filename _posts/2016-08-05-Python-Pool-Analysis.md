---
layout: project
title: Python Pool Analysis
start_date: August 2016
date: 2016-08-05 00:00:00 -0600
category: projects
tags: explore
image: /assets/python-pool-performance/perf.png
media: [python-pool-performance/perf.png]
---
It can be difficult to find the best method for parallel data processing. In Python, I came across six different options to run the same code concurrently, some truly in parallel and some interleaved as green threads. Overwhelmed with options, I decided to dig into the details of each pool and really test them.

I created a CLI to test each of the six different Python pool implementations for both CPU and I/O-bounded workloads. I have open sourced the CLI and my test results [on GitHub][github] so other developers can run their own tests or just skip the dirty details of benchmarking and use my own conclusions as a reference.


[github]: https://github.com/JohnStarich/python-pool-performance

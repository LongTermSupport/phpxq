# phpxq benchmark report

Label: `perf-jq-before`  
Started: 2026-10-03T22:16:35+00:00

## Environment

| setting | value |
| --- | --- |
| php_version | 8.5.11 |
| php_binary | /usr/bin/php8.5 |
| php_sapi | cli |
| opcache_loaded | yes |
| opcache_enable_cli | 0 |
| jit | disable |
| jit_buffer_size | 64M |
| xdebug_loaded | no |
| memory_limit | -1 |
| cpu_model | Intel(R) Xeon(R) E-2236 CPU @ 3.40GHz |
| cpu_cores | 8 |
| kernel | 7.2.8-200.fc44.x86_64 |
| os | Linux |
| hostname | 5dc514e2f2e9 |
| repetitions | 5 |
| warmup | 2 |
| sizes | small,medium,large |
| tools | jq |
| filter | none |
| binary | none |
| commit | 69a7860 |
| subject_php | php |

## Targets

| id | role | version | command |
| --- | --- | --- | --- |
| phpxq-jq | subject | 69a7860 | php /workspace/.claude/worktrees/wf-c206089e-50f-1-060403c8/bin/phpxq jq |
| jq | reference | jq-1.6 | /usr/bin/jq |

## Results

Median wall-clock milliseconds per sample; `vs ref` is the median relative to the reference tool, `vs base` relative to the baseline. Above 1.00x is slower.

### jq:startup

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-jq | ok | 88.92 | 57.87 | 107.51 | 22.8 | 1.58x | - |
| jq | ok | 56.33 | 36.10 | 90.32 | 42.7 | - | - |

### jq:many-small

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-jq | ok | 1328.79 | 1292.40 | 1593.09 | 10.1 | 2.23x | - |
| jq | ok | 594.63 | 588.07 | 609.80 | 1.7 | - | - |

### jq:identity-small

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-jq | ok | 75.53 | 56.98 | 95.59 | 23.2 | 1.93x | - |
| jq | ok | 39.23 | 33.22 | 61.14 | 29.0 | - | - |

### jq:select-small

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-jq | ok | 102.01 | 93.11 | 165.24 | 28.2 | 3.36x | - |
| jq | ok | 30.40 | 27.95 | 40.05 | 17.6 | - | - |

### jq:identity-medium

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-jq | ok | 133.89 | 95.56 | 160.22 | 22.0 | 1.48x | - |
| jq | ok | 90.26 | 74.65 | 98.55 | 12.0 | - | - |

### jq:select-medium

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-jq | ok | 75.26 | 72.99 | 79.23 | 3.5 | 1.52x | - |
| jq | ok | 49.58 | 47.15 | 64.59 | 16.1 | - | - |

### jq:identity-large

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-jq | ok | 1220.55 | 984.79 | 1484.26 | 18.5 | 1.00x | - |
| jq | ok | 1215.18 | 1188.94 | 1334.46 | 5.3 | - | - |

### jq:select-large

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-jq | ok | 864.35 | 586.29 | 976.25 | 24.0 | 0.96x | - |
| jq | ok | 902.28 | 670.81 | 1133.62 | 20.2 | - | - |

### jq:aggregate-medium

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-jq | ok | 82.52 | 73.28 | 96.40 | 11.4 | 1.07x | - |
| jq | ok | 77.28 | 61.21 | 101.13 | 23.1 | - | - |

### jq:group-medium

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-jq | ok | 130.01 | 123.98 | 206.11 | 26.0 | 1.99x | - |
| jq | ok | 65.29 | 59.10 | 98.89 | 25.8 | - | - |

### jq:aggregate-large

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-jq | ok | 682.94 | 593.57 | 837.97 | 15.2 | 0.57x | - |
| jq | ok | 1203.23 | 1101.20 | 1517.47 | 15.2 | - | - |

### jq:group-large

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-jq | ok | 2261.91 | 2089.99 | 2490.38 | 8.0 | 1.96x | - |
| jq | ok | 1151.42 | 1125.09 | 1311.26 | 7.7 | - | - |

### jq:wide-keys

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-jq | ok | 62.37 | 60.84 | 80.81 | 15.2 | 1.33x | - |
| jq | ok | 46.90 | 40.11 | 49.73 | 8.9 | - | - |

### jq:deep-walk

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-jq | ok | 75.95 | 61.88 | 85.18 | 13.1 | 2.37x | - |
| jq | ok | 32.10 | 29.12 | 35.32 | 9.3 | - | - |

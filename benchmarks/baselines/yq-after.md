# phpxq benchmark report

Label: `yq-after`  
Started: 2026-10-04T07:27:11+00:00

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
| repetitions | 3 |
| warmup | 1 |
| sizes | small,medium |
| tools | yq |
| filter | none |
| binary | none |
| commit | 55f4e90 |
| subject_php | php |

## Targets

| id | role | version | command |
| --- | --- | --- | --- |
| phpxq-yq | subject | 55f4e90 | php /workspace/.claude/worktrees/wf-c206089e-50f-2-9702b344/bin/phpxq yq |
| yq | reference | yq (https://github.com/mikefarah/yq/) version v4.54.1 | /usr/bin/yq |

## Results

Median wall-clock milliseconds per sample; `vs ref` is the median relative to the reference tool, `vs base` relative to the baseline. Above 1.00x is slower.

### yq:startup

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-yq | ok | 89.61 | 58.88 | 161.53 | 53.9 | 4.43x | - |
| yq | ok | 20.24 | 8.86 | 35.85 | 65.1 | - | - |

### yq:many-small

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-yq | ok | 2197.59 | 2189.59 | 3462.11 | 30.5 | 5.51x | - |
| yq | ok | 398.56 | 363.72 | 557.81 | 25.5 | - | - |

### yq:identity-small

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-yq | ok | 94.22 | 90.34 | 100.09 | 5.5 | 2.43x | - |
| yq | ok | 38.78 | 34.09 | 64.23 | 38.2 | - | - |

### yq:select-small

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-yq | ok | 127.97 | 72.80 | 152.03 | 35.2 | 2.19x | - |
| yq | ok | 58.41 | 52.72 | 87.26 | 30.3 | - | - |

### yq:identity-medium

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-yq | ok | 1074.54 | 623.40 | 1282.56 | 34.7 | 0.95x | - |
| yq | ok | 1134.72 | 944.04 | 1227.33 | 13.5 | - | - |

### yq:select-medium

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-yq | ok | 405.56 | 356.95 | 432.62 | 9.9 | 0.96x | - |
| yq | ok | 422.86 | 344.54 | 527.29 | 22.5 | - | - |

### yq:aggregate-medium

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-yq | ok | 443.10 | 402.81 | 493.79 | 10.8 | 0.68x | - |
| yq | ok | 651.24 | 625.48 | 812.59 | 15.9 | - | - |

### yq:group-medium

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-yq | ok | 501.55 | 487.19 | 532.91 | 5.0 | 0.64x | - |
| yq | ok | 777.94 | 729.95 | 925.85 | 13.6 | - | - |

### yq:wide-keys

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-yq | ok | 276.31 | 258.76 | 311.57 | 10.2 | 1.45x | - |
| yq | ok | 190.06 | 178.65 | 249.87 | 20.2 | - | - |

### yq:deep-walk

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-yq | ok | 82.35 | 71.31 | 190.62 | 61.3 | 2.27x | - |
| yq | ok | 36.31 | 25.76 | 63.20 | 49.0 | - | - |

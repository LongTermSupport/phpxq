# phpxq benchmark report

Label: `yq-before`  
Started: 2026-10-03T22:30:29+00:00

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
| commit | 69a7860 |
| subject_php | php |

## Targets

| id | role | version | command |
| --- | --- | --- | --- |
| phpxq-yq | subject | 69a7860 | php /workspace/.claude/worktrees/wf-c206089e-50f-2-9702b344/bin/phpxq yq |
| yq | reference | yq (https://github.com/mikefarah/yq/) version v4.54.1 | /usr/bin/yq |

## Results

Median wall-clock milliseconds per sample; `vs ref` is the median relative to the reference tool, `vs base` relative to the baseline. Above 1.00x is slower.

### yq:startup

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-yq | ok | 499.09 | 381.59 | 516.13 | 15.9 | 7.50x | - |
| yq | ok | 66.57 | 27.71 | 138.02 | 75.3 | - | - |

### yq:many-small

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-yq | ok | 7406.03 | 5695.45 | 9336.20 | 25.6 | 9.89x | - |
| yq | ok | 749.01 | 597.56 | 786.89 | 14.3 | - | - |

### yq:identity-small

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-yq | ok | 646.69 | 213.30 | 668.63 | 50.5 | 9.77x | - |
| yq | ok | 66.17 | 16.92 | 182.07 | 99.2 | - | - |

### yq:select-small

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-yq | ok | 176.69 | 172.90 | 214.61 | 13.5 | 4.93x | - |
| yq | ok | 35.84 | 34.08 | 59.45 | 35.6 | - | - |

### yq:identity-medium

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-yq | ok | 7679.85 | 6410.03 | 9856.44 | 23.2 | 4.37x | - |
| yq | ok | 1756.75 | 1395.05 | 2413.11 | 29.6 | - | - |

### yq:select-medium

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-yq | ok | 5674.57 | 5619.06 | 6385.66 | 8.0 | 8.90x | - |
| yq | ok | 637.85 | 611.36 | 753.35 | 12.3 | - | - |

### yq:aggregate-medium

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-yq | ok | 11065.99 | 8823.07 | 12787.07 | 19.0 | 10.14x | - |
| yq | ok | 1091.38 | 1069.19 | 1921.21 | 38.7 | - | - |

### yq:group-medium

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-yq | ok | 12524.66 | 12296.73 | 14351.17 | 9.5 | 12.50x | - |
| yq | ok | 1001.93 | 940.62 | 1527.62 | 30.3 | - | - |

### yq:wide-keys

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-yq | ok | 2656.48 | 2629.44 | 3465.62 | 17.9 | 7.52x | - |
| yq | ok | 353.09 | 271.31 | 519.04 | 35.2 | - | - |

### yq:deep-walk

| target | status | median ms | min ms | p95 ms | CV % | vs ref | vs base |
| --- | --- | --- | --- | --- | --- | --- | --- |
| phpxq-yq | ok | 253.85 | 220.59 | 305.43 | 17.5 | 13.20x | - |
| yq | ok | 19.23 | 16.21 | 50.94 | 71.0 | - | - |

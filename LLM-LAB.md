# LLM 主翻译旁路实验

本分支从线上稳定版派生，默认行为与 `master` 完全一致。只有在独立测试站显式设置 `lisun_llm_lab_enabled=1` 时，Translation V2 才会使用 LLM 实验适配器。

实验适配器复用现有的文章级术语筛选、V2 翻译单元、格式占位符、术语锚点、恢复和完整性校验。第一阶段每个语义单元单独请求，任何截断、说明性废话、XML/标签破坏或锚点丢失都会阻止该单元进入后续保存流程。

测试站可使用 WP-CLI 配置：

```bash
wp option update lisun_llm_lab_enabled 1
wp option update lisun_llm_lab_provider deepseek
wp option update lisun_llm_lab_api_url https://api.deepseek.com/chat/completions
wp option update lisun_llm_lab_model deepseek-chat
wp option update lisun_llm_lab_api_key 'TEST_KEY'
```

关闭实验并恢复原引擎：

```bash
wp option update lisun_llm_lab_enabled 0
```

不要在生产站开启该选项。第一阶段目标是收集 DeepL 与 LLM 对照结果，不替换线上文章、不推送远程站。

# LLM ��������·ʵ��

����֧�������ȶ���������Ĭ����Ϊ�� `master` ��ȫһ�¡�ֻ���ڶ�������վ��ʽ���� `lisun_llm_lab_enabled=1` ʱ��Translation V2 �Ż�ʹ�� LLM ʵ����������

ʵ���������������е����¼�����ɸѡ��V2 ���뵥Ԫ����ʽռλ��������ê�㡢�ָ���������У�顣��һ�׶�ÿ�����嵥Ԫ���������κνضϡ�˵���Էϻ���XML/��ǩ�ƻ���ê�㶪ʧ������ֹ�õ�Ԫ��������������̡�

����վ��ʹ�� WP-CLI ���ã�

```bash
wp option update lisun_llm_lab_enabled 1
wp option update lisun_llm_lab_provider deepseek
wp option update lisun_llm_lab_api_url https://api.deepseek.com/chat/completions
wp option update lisun_llm_lab_model deepseek-chat
wp option update lisun_llm_lab_api_key 'TEST_KEY'
```

�ر�ʵ�鲢�ָ�ԭ���棺

```bash
wp option update lisun_llm_lab_enabled 0
```

��Ҫ������վ������ѡ���һ�׶�Ŀ�����ռ� DeepL �� LLM ���ս�������滻�������¡�������Զ��վ��


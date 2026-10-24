<?php

declare(strict_types=1);

// Consumer-owned synthetic app-server. Protocol shapes follow prism-acp 050d9d5 (MIT); no model or real tool runs.
$thread = '418fb379-86dc-456a-ac17-ab1df70f7216';
$turn = 'fixture-turn';
$approval = null;
$emit = static function (array $frame): void {
    fwrite(STDOUT, json_encode($frame, JSON_THROW_ON_ERROR)."\n");
    fflush(STDOUT);
};
$finish = static function (string $text) use ($emit, &$turn, $thread): void {
    $emit(['jsonrpc' => '2.0', 'method' => 'item/completed', 'params' => ['threadId' => $thread, 'turnId' => $turn,
        'item' => ['id' => 'answer-'.$turn, 'type' => 'agentMessage', 'text' => $text]]]);
    $emit(['jsonrpc' => '2.0', 'method' => 'turn/completed', 'params' => ['threadId' => $thread, 'turn' => ['id' => $turn, 'status' => 'completed']]]);
};
while (($line = fgets(STDIN)) !== false) {
    $frame = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    if ($approval !== null && ($frame['id'] ?? null) === $approval && ! isset($frame['method'])) {
        $decision = $frame['result']['decision'] ?? null;
        $finish(in_array($decision, ['decline', 'cancel'], true) ? 'permission denied' : 'UNSAFE permission accepted');
        $approval = null;

        continue;
    }
    if (! array_key_exists('id', $frame)) {
        continue;
    }
    $method = $frame['method'] ?? '';
    $result = new stdClass;
    switch ($method) {
        case 'initialize':
            $result = ['userAgent' => 'owned-codex-fixture'];
            break;
        case 'config/read':
            $result = ['config' => ['private_fixture' => 'dummy-sensitive-config', 'mcp_servers' => in_array('--fixture-invalid-config', $argv, true) ? null
                : ['inherited' => ['url' => 'http://127.0.0.1:1', 'http_headers' => ['X-Dummy' => 'dummy-sensitive-config']]]]];
            break;
        case 'thread/start':
        case 'thread/resume':
            $result = ['thread' => ['id' => $thread], 'model' => 'fixture-model'];
            break;
        case 'thread/turns/list':
            $result = ['data' => [['id' => 'old-turn']], 'nextCursor' => null];
            break;
        case 'thread/items/list':
            if (in_array('--fixture-slow-history', $argv, true)) {
                usleep(500_000);
            }
            $result = ['data' => [
                ['item' => ['id' => 'old-question', 'type' => 'userMessage', 'content' => [['type' => 'text', 'text' => 'earlier question']]]],
                ['item' => ['id' => 'old-answer', 'type' => 'agentMessage', 'text' => 'earlier answer']],
            ], 'nextCursor' => null];
            break;
        case 'model/list':
            $result = ['data' => [['id' => 'fixture-model', 'model' => 'fixture-model', 'displayName' => 'Fixture', 'isDefault' => true]], 'nextCursor' => null];
            break;
        case 'account/rateLimits/read':
            $result = ['rateLimits' => ['primary' => ['usedPercent' => 0, 'windowDurationMins' => 300], 'secondary' => null]];
            break;
        case 'turn/start':
            $result = ['turn' => ['id' => $turn]];
            break;
    }
    $emit(['jsonrpc' => '2.0', 'id' => $frame['id'], 'result' => $result]);
    if ($method === 'turn/start') {
        $prompt = implode(' ', array_column($frame['params']['input'] ?? [], 'text'));
        $emit(['jsonrpc' => '2.0', 'method' => 'turn/started', 'params' => ['threadId' => $thread, 'turn' => ['id' => $turn]]]);
        if ($prompt === 'approval') {
            $approval = 'native-permission';
            $emit(['jsonrpc' => '2.0', 'id' => $approval, 'method' => 'item/commandExecution/requestApproval',
                'params' => ['threadId' => $thread, 'turnId' => $turn, 'itemId' => 'future-command', 'command' => 'owned fixture, never executed', 'cwd' => getcwd()]]);
        } else {
            $finish('echo: '.$prompt);
        }
    }
}

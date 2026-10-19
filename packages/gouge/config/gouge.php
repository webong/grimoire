<?php

return [
    'connection' => null,
    'provider' => null,
    'model' => null,
    'timeout' => null,
    // Exact name@version => trusted snapshot: digest, instructions, admitted tool class names.
    // Registry-backed deployments should bind Contracts\AgentFactory to their verified resolver.
    'definitions' => [],
];

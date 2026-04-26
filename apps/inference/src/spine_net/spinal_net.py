from __future__ import annotations

import numpy as np
import torch.nn as nn

from . import resnet
from .dec_net import DecNet


class SpineNet(nn.Module):
    def __init__(self, heads, down_ratio, final_kernel, head_conv):
        super().__init__()
        assert down_ratio in (2, 4, 8, 16)
        channels = [3, 64, 64, 128, 256, 512]
        self.l1 = int(np.log2(down_ratio))
        self.base_network = resnet.resnet34()
        self.dec_net = DecNet(heads, final_kernel, head_conv, channels[self.l1])

    def forward(self, x):
        x = self.base_network(x)
        return self.dec_net(x)

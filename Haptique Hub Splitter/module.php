<?php

declare(strict_types=1);
	class HaptiqueHubSplitter extends IPSModuleStrict
	{
		public function Create(): void
        {
			//Never delete this line!
			parent::Create();

            //we will wait until the kernel is ready
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
		}

		public function Destroy(): void
        {
			//Never delete this line!
			parent::Destroy();
		}

		public function ApplyChanges(): void
        {
			//Never delete this line!
			parent::ApplyChanges();
		}

        public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
        {
            //Never delete this line!
            parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

            if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
                $this->SendDebug("MessageSink", "🔄 Kernel Ready", 0);
            }

            if ($Message == IPS_KERNELSTARTED) {
                $this->SendDebug("MessageSink", "🔄 Kernel Started", 0);
            }

            if ($Message == IM_CHANGESTATUS && $Data[0] == IS_ACTIVE) {
                $this->SendDebug("MessageSink", "🔄 Instanz aktiv", 0);
            }
        }
	}